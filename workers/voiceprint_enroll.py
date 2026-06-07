#!/usr/bin/env python3
# workers/voiceprint_enroll.py - 声纹特征提取脚本
"""
用法: python3 voiceprint_enroll.py --voiceprint-id vp_xxx --audio-file /path/to/audio.wav

流程:
1. 加载音频 → 重采样到 16kHz 单声道
2. 调用 SpeakerEncoder 提取声纹特征向量
3. 保存特征向量到 .npy 文件
4. 更新数据库状态为 ready
"""

import argparse
import os
import sys
import json
import traceback
from datetime import datetime

# 项目根目录（worker 在 workers/ 子目录）
PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, PROJECT_ROOT)

# ---- 配置 ----
def load_config():
    config_path = os.path.join(PROJECT_ROOT, 'config.php')
    # PHP 配置简单解析为 Python dict（或使用环境变量）
    return {
        'db': {
            'host': os.getenv('DB_HOST', '127.0.0.1'),
            'port': int(os.getenv('DB_PORT', 3306)),
            'database': os.getenv('DB_NAME', 'voiceprint_converter'),
            'user': os.getenv('DB_USER', 'root'),
            'password': os.getenv('DB_PASS', ''),
        },
        'paths': {
            'voiceprints': os.path.join(PROJECT_ROOT, 'uploads', 'voiceprints'),
            'results': os.path.join(PROJECT_ROOT, 'results'),
            'logs': os.path.join(PROJECT_ROOT, 'logs'),
        }
    }

# ---- 数据库 ----
def get_db(config):
    try:
        import pymysql
        return pymysql.connect(
            host=config['db']['host'],
            port=config['db']['port'],
            user=config['db']['user'],
            password=config['db']['password'],
            database=config['db']['database'],
            charset='utf8mb4',
            autocommit=True,
        )
    except ImportError:
        import mysql.connector
        return mysql.connector.connect(
            host=config['db']['host'],
            port=config['db']['port'],
            user=config['db']['user'],
            password=config['db']['password'],
            database=config['db']['database'],
            charset='utf8mb4',
            autocommit=True,
        )

def update_voiceprint_status(db, voiceprint_id, status, embedding_path=None, error=None):
    cursor = db.cursor()
    if status == 'ready' and embedding_path:
        cursor.execute(
            'UPDATE voiceprints SET status = %s, embedding_path = %s, updated_at = %s WHERE voiceprint_id = %s',
            (status, embedding_path, datetime.now().strftime('%Y-%m-%d %H:%M:%S'), voiceprint_id)
        )
    elif status == 'failed':
        cursor.execute(
            'UPDATE voiceprints SET status = %s, error_message = %s, updated_at = %s WHERE voiceprint_id = %s',
            (status, error or 'Unknown error', datetime.now().strftime('%Y-%m-%d %H:%M:%S'), voiceprint_id)
        )
    else:
        cursor.execute(
            'UPDATE voiceprints SET status = %s, updated_at = %s WHERE voiceprint_id = %s',
            (status, datetime.now().strftime('%Y-%m-%d %H:%M:%S'), voiceprint_id)
        )
    cursor.close()

# ---- 音频处理 ----
def preprocess_audio(audio_path, target_sr=16000):
    """加载并预处理音频: 重采样到 target_sr, 转单声道"""
    try:
        import librosa
        import soundfile as sf
    except ImportError:
        print('[ERROR] 请安装依赖: pip install librosa soundfile', file=sys.stderr)
        raise

    audio, sr = librosa.load(audio_path, sr=target_sr, mono=True)
    return audio, target_sr

# ---- 声纹提取 ----
def extract_voiceprint(audio, sr=16000):
    """
    提取声纹特征向量
    
    实现方案 (按推荐度排序):
    1. Resemblyzer (最易用) — pip install resemblyzer
    2. SpeechBrain ECAPA-TDNN (更高精度)
    3. WeSpeaker / CAM++ (中文优化)
    
    此处使用 Resemblyzer 作为默认方案
    """
    try:
        from resemblyzer import VoiceEncoder
    except ImportError:
        print('[ERROR] 请安装 Resemblyzer: pip install resemblyzer', file=sys.stderr)
        print('[INFO] 或安装 SpeechBrain: pip install speechbrain', file=sys.stderr)
        raise

    encoder = VoiceEncoder()
    # 提取嵌入向量 (256维)
    embedding = encoder.embed_utterance(audio)
    return embedding  # shape: (256,)

# ---- 主流程 ----
def main():
    parser = argparse.ArgumentParser(description='声纹特征提取')
    parser.add_argument('--voiceprint-id', required=True, help='声纹 ID')
    parser.add_argument('--audio-file', required=True, help='音频文件路径')
    args = parser.parse_args()

    voiceprint_id = args.voiceprint_id
    audio_path = args.audio_file

    print(f'[{datetime.now()}] 开始提取声纹: {voiceprint_id}')
    print(f'  音频文件: {audio_path}')

    config = load_config()
    db = get_db(config)

    try:
        # 更新状态为 extracting
        update_voiceprint_status(db, voiceprint_id, 'extracting')
        print('  状态: extracting')

        # 预处理
        audio, sr = preprocess_audio(audio_path)
        print(f'  音频加载: {len(audio)/sr:.1f}s, {sr}Hz')

        if len(audio) / sr < 3:
            raise ValueError(f'音频过短 ({len(audio)/sr:.1f}s)，至少需要 3 秒')

        # 提取特征
        embedding = extract_voiceprint(audio, sr)
        print(f'  特征提取完成, 维度: {embedding.shape}')

        # 保存特征向量
        import numpy as np
        embedding_dir = os.path.join(config['paths']['voiceprints'], 'embeddings')
        os.makedirs(embedding_dir, exist_ok=True)
        embedding_path = os.path.join(embedding_dir, f'{voiceprint_id}.npy')
        np.save(embedding_path, embedding)
        print(f'  特征已保存: {embedding_path}')

        # 更新数据库
        update_voiceprint_status(db, voiceprint_id, 'ready', embedding_path=embedding_path)
        print(f'[{datetime.now()}] 声纹提取成功: {voiceprint_id}')

    except Exception as e:
        error_msg = f'{type(e).__name__}: {e}'
        print(f'[ERROR] {error_msg}')
        traceback.print_exc()
        update_voiceprint_status(db, voiceprint_id, 'failed', error=error_msg)
        sys.exit(1)

    finally:
        db.close()

if __name__ == '__main__':
    main()
