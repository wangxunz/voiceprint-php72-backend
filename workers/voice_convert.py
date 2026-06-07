#!/usr/bin/env python3
# workers/voice_convert.py - 变声处理流水线
"""
用法: python3 voice_convert.py --task-id task_xxx

处理流水线:
1. 加载任务信息（从数据库）
2. 更新状态: separating → 调用 Spleeter/Demucs 分离人声
3. 更新状态: converting → 调用 RVC/So-VITS-SVC 执行声纹转换
4. 更新状态: rendering → 合成最终音频（变声人声 + 伴奏）
5. 完成 → 写结果文件 + 更新状态为 completed
"""

import argparse
import os
import sys
import subprocess
import json
import traceback
from datetime import datetime

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, PROJECT_ROOT)

# ---- 配置 ----
def load_config():
    return {
        'db': {
            'host': os.getenv('DB_HOST', '127.0.0.1'),
            'port': int(os.getenv('DB_PORT', 3306)),
            'database': os.getenv('DB_NAME', 'voiceprint_converter'),
            'user': os.getenv('DB_USER', 'root'),
            'password': os.getenv('DB_PASS', ''),
        },
        'paths': {
            'temp': os.path.join(PROJECT_ROOT, 'uploads', 'temp'),
            'results': os.path.join(PROJECT_ROOT, 'results'),
            'logs': os.path.join(PROJECT_ROOT, 'logs'),
        },
        'python': {
            'spleeter_path': os.getenv('SPLEETER_PATH', 'spleeter'),
            'rvc_path': os.getenv('RVC_PATH', ''),
            'timeout': int(os.getenv('PYTHON_TIMEOUT', 600)),
        }
    }

# ---- 数据库 ----
def get_db(config):
    try:
        import pymysql
        return pymysql.connect(
            host=config['db']['host'], port=config['db']['port'],
            user=config['db']['user'], password=config['db']['password'],
            database=config['db']['database'], charset='utf8mb4', autocommit=True,
        )
    except ImportError:
        import mysql.connector
        return mysql.connector.connect(
            host=config['db']['host'], port=config['db']['port'],
            user=config['db']['user'], password=config['db']['password'],
            database=config['db']['database'], charset='utf8mb4', autocommit=True,
        )

def get_task(db, task_id):
    cursor = db.cursor(dictionary=True)
    cursor.execute(
        'SELECT t.*, v.embedding_path, v.file_path as voiceprint_file '
        'FROM conversion_tasks t '
        'JOIN voiceprints v ON t.voiceprint_id = v.voiceprint_id '
        'WHERE t.task_id = %s',
        (task_id,)
    )
    row = cursor.fetchone()
    cursor.close()
    return row

def update_task(db, task_id, **kwargs):
    cursor = db.cursor()
    sets = []
    values = []
    for key, val in kwargs.items():
        sets.append(f'{key} = %s')
        values.append(val)
    values.append(datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
    values.append(task_id)
    sql = f"UPDATE conversion_tasks SET {', '.join(sets)}, updated_at = %s WHERE task_id = %s"
    cursor.execute(sql, values)
    cursor.close()

def update_progress(db, task_id, state, progress, error=None):
    kwargs = {'state': state, 'progress': progress}
    if error:
        kwargs['error_message'] = error
    update_task(db, task_id, **kwargs)

# ---- 步骤 1: 人声分离 ----
def separate_vocals(song_path, output_dir, task_id, config):
    """使用 Spleeter 或 Demucs 分离人声和伴奏"""
    spleeter = config['python']['spleeter_path']
    
    print(f'  [separate] 分离人声: {song_path}')
    
    # 尝试使用 spleeter
    cmd = [
        spleeter, 'separate',
        '-p', 'spleeter:2stems',  # 2 轨道: vocals + accompaniment
        '-o', output_dir,
        song_path
    ]
    
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=config['python']['timeout'])
        if result.returncode != 0:
            # 回退到 demucs
            print(f'  [separate] Spleeter 失败，尝试 Demucs...')
            print(f'  [separate] stderr: {result.stderr[:200]}')
            return separate_with_demucs(song_path, output_dir, task_id, config)
        
        # Spleeter 输出目录: output_dir/<filename>/
        base_name = os.path.splitext(os.path.basename(song_path))[0]
        vocals_path = os.path.join(output_dir, base_name, 'vocals.wav')
        accompaniment_path = os.path.join(output_dir, base_name, 'accompaniment.wav')
        
        if os.path.exists(vocals_path):
            print(f'  [separate] 人声: {vocals_path}')
            print(f'  [separate] 伴奏: {accompaniment_path}')
            return vocals_path, accompaniment_path
        else:
            raise FileNotFoundError(f'Spleeter 未生成预期文件: {vocals_path}')
            
    except subprocess.TimeoutExpired:
        raise RuntimeError('人声分离超时')
    except FileNotFoundError:
        print(f'  [separate] 未找到 Spleeter，尝试 Demucs...')
        return separate_with_demucs(song_path, output_dir, task_id, config)

def separate_with_demucs(song_path, output_dir, task_id, config):
    """使用 Demucs 分离人声"""
    cmd = [
        'python3', '-m', 'demucs',
        '--two-stems', 'vocals',
        '-o', output_dir,
        song_path
    ]
    
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=config['python']['timeout'])
        if result.returncode != 0:
            raise RuntimeError(f'Demucs 失败: {result.stderr[:300]}')
        
        base_name = os.path.splitext(os.path.basename(song_path))[0]
        model_dir = os.path.join(output_dir, 'htdemucs', base_name)
        vocals_path = os.path.join(model_dir, 'vocals.wav')
        no_vocals_path = os.path.join(model_dir, 'no_vocals.wav')
        
        if os.path.exists(vocals_path):
            return vocals_path, no_vocals_path
        else:
            raise FileNotFoundError(f'Demucs 未生成预期文件: {vocals_path}')
            
    except subprocess.TimeoutExpired:
        raise RuntimeError('人声分离超时(Demucs)')

# ---- 步骤 2: 声纹转换 ----
def convert_voice(vocals_path, voiceprint_embedding, output_path, pitch_shift, config):
    """
    将分离出的人声替换为目标声纹
    
    推荐方案:
    1. RVC (Retrieval-based Voice Conversion) — 最佳音质
    2. So-VITS-SVC — 中文优化
    3. OpenVoice — 轻量级，支持零样本
    
    此脚本提供 RVC 和 OpenVoice 两种接口
    """
    rvc_path = config['python'].get('rvc_path', '')
    
    if rvc_path and os.path.exists(rvc_path):
        return convert_with_rvc(vocals_path, voiceprint_embedding, output_path, pitch_shift, rvc_path)
    else:
        print('  [convert] RVC 路径未配置，使用 OpenVoice 方案')
        return convert_with_openvoice(vocals_path, voiceprint_embedding, output_path, pitch_shift)

def convert_with_rvc(vocals_path, embedding_path, output_path, pitch_shift, rvc_path):
    """使用 RVC 进行声纹转换"""
    import numpy as np
    
    embedding = np.load(embedding_path)
    
    # RVC 推理脚本调用
    # 实际部署时需根据 RVC 项目结构调整路径和参数
    infer_script = os.path.join(rvc_path, 'infer.py')
    if not os.path.exists(infer_script):
        infer_script = os.path.join(rvc_path, 'tools', 'infer.py')
    
    cmd = [
        'python3', infer_script,
        '--input', vocals_path,
        '--output', output_path,
        '--embedding', embedding_path,
        '--pitch', str(pitch_shift),
        '--f0method', 'rmvpe',
    ]
    
    print(f'  [convert] RVC 推理: {" ".join(cmd)}')
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
    
    if result.returncode != 0:
        raise RuntimeError(f'RVC 转换失败: {result.stderr[:300]}')
    
    return output_path

def convert_with_openvoice(vocals_path, embedding_path, output_path, pitch_shift):
    """使用 OpenVoice 进行零样本声纹转换"""
    import numpy as np
    import librosa
    import soundfile as sf
    
    print('  [convert] 使用 OpenVoice 进行零样本转换')
    
    # 加载人声音频
    audio, sr = librosa.load(vocals_path, sr=16000, mono=True)
    
    # 加载声纹嵌入
    embedding = np.load(embedding_path)
    
    try:
        # 尝试导入 OpenVoice
        from openvoice import se_extractor
        from openvoice.api import ToneColorConverter
        
        # 创建 ToneColorConverter
        # 注意：实际使用时需要先下载模型
        ckpt_converter = os.path.join(PROJECT_ROOT, 'models', 'converter')
        
        if not os.path.exists(ckpt_converter):
            raise FileNotFoundError(
                f'OpenVoice 模型未下载，请先执行:\n'
                f'  git clone https://github.com/myshell-ai/OpenVoice.git\n'
                f'  并下载检查点到 models/converter/'
            )
        
        tone_converter = ToneColorConverter(ckpt_converter, device='cpu')
        
        # 提取源音频的音色
        source_se, _ = se_extractor.get_se(vocals_path, tone_converter, vad=False)
        
        # 执行转换
        converted = tone_converter.convert(
            audio_src_path=vocals_path,
            src_se=source_se,
            tgt_se=embedding,
            output_path=output_path,
        )
        
        return output_path
        
    except ImportError:
        # 回退方案: 简单重采样 + 音调变换（仅作演示）
        print('  [convert] OpenVoice 未安装，使用简化方案（仅音调变换）')
        return fallback_pitch_shift(vocals_path, output_path, pitch_shift)

def fallback_pitch_shift(input_path, output_path, semitones):
    """回退方案：仅做音调变换"""
    import librosa
    import soundfile as sf
    
    audio, sr = librosa.load(input_path, sr=44100, mono=True)
    shifted = librosa.effects.pitch_shift(y=audio, sr=sr, n_steps=semitones)
    
    output_dir = os.path.dirname(output_path)
    os.makedirs(output_dir, exist_ok=True)
    sf.write(output_path, shifted, sr)
    
    print(f'  [convert] 音调变换完成: {semitones} 半音')
    return output_path

# ---- 步骤 3: 合成最终音频 ----
def mix_audio(converted_vocals_path, accompaniment_path, output_path):
    """将变声后的人声与伴奏混合"""
    import librosa
    import soundfile as sf
    import numpy as np
    
    print(f'  [mix] 混音: vocals + accompaniment')
    
    # 加载音轨
    vocals, sr1 = librosa.load(converted_vocals_path, sr=None, mono=False)
    accomp, sr2 = librosa.load(accompaniment_path, sr=None, mono=False)
    
    # 统一采样率
    target_sr = 44100
    if sr1 != target_sr:
        vocals = librosa.resample(y=vocals if vocals.ndim == 1 else vocals[0], 
                                   orig_sr=sr1, target_sr=target_sr)
    if sr2 != target_sr:
        accomp = librosa.resample(y=accomp if accomp.ndim == 1 else accomp[0],
                                   orig_sr=sr2, target_sr=target_sr)
    
    # 确保长度一致
    max_len = max(len(vocals), len(accomp))
    vocals = np.pad(vocals, (0, max_len - len(vocals)))
    accomp = np.pad(accomp, (0, max_len - len(accomp)))
    
    # 混合: 人声 70% + 伴奏 100%
    mixed = vocals * 0.7 + accomp * 1.0
    
    # 防止削波
    max_val = np.abs(mixed).max()
    if max_val > 0.99:
        mixed = mixed / max_val * 0.95
    
    output_dir = os.path.dirname(output_path)
    os.makedirs(output_dir, exist_ok=True)
    sf.write(output_path, mixed, target_sr)
    
    duration = len(mixed) / target_sr
    print(f'  [mix] 混音完成: {output_path} ({duration:.1f}s)')
    return duration

# ---- 主流程 ----
def main():
    parser = argparse.ArgumentParser(description='变声处理流水线')
    parser.add_argument('--task-id', required=True, help='任务 ID')
    args = parser.parse_args()

    task_id = args.task_id
    config = load_config()
    db = get_db(config)

    print(f'[{datetime.now()}] 开始处理任务: {task_id}')

    try:
        # 加载任务
        task = get_task(db, task_id)
        if not task:
            raise ValueError(f'任务不存在: {task_id}')
        
        song_path = task['song_file_path']
        voiceprint_id = task['voiceprint_id']
        embedding_path = task['embedding_path']
        pitch_shift = task['pitch_shift']
        
        print(f'  歌曲: {task["song_name"]} ({song_path})')
        print(f'  声纹: {voiceprint_id}')
        print(f'  音调偏移: {pitch_shift} 半音')

        task_dir = os.path.join(config['paths']['temp'], task_id)
        os.makedirs(task_dir, exist_ok=True)

        # ---- 步骤 1: 人声分离 ----
        print(f'\n[{datetime.now()}] 步骤 1/3: 人声分离')
        update_progress(db, task_id, 'separating', 10)
        
        vocals_path, accomp_path = separate_vocals(song_path, task_dir, task_id, config)
        update_progress(db, task_id, 'separating', 33)

        # ---- 步骤 2: 声纹转换 ----
        print(f'\n[{datetime.now()}] 步骤 2/3: 声纹转换')
        update_progress(db, task_id, 'converting', 40)
        
        converted_path = os.path.join(task_dir, f'{task_id}_converted.wav')
        convert_voice(vocals_path, embedding_path, converted_path, pitch_shift, config)
        update_progress(db, task_id, 'converting', 70)

        # ---- 步骤 3: 合成输出 ----
        print(f'\n[{datetime.now()}] 步骤 3/3: 合成输出')
        update_progress(db, task_id, 'rendering', 80)
        
        result_path = os.path.join(config['paths']['results'], f'{task_id}.mp3')
        duration = mix_audio(converted_path, accomp_path, result_path)

        # 完成
        update_task(db, task_id,
                    state='completed',
                    progress=100,
                    result_path=result_path,
                    song_duration=int(duration))
        
        print(f'\n[{datetime.now()}] 任务完成: {task_id}')
        print(f'  结果文件: {result_path}')

    except Exception as e:
        error_msg = f'{type(e).__name__}: {str(e)[:500]}'
        print(f'\n[ERROR] {error_msg}')
        traceback.print_exc()
        try:
            update_progress(db, task_id, 'failed', 0, error=error_msg)
        except Exception:
            pass
        sys.exit(1)

    finally:
        db.close()

if __name__ == '__main__':
    main()
