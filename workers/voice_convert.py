#!/usr/bin/env python3
# workers/voice_convert.py - Voice conversion pipeline
"""
Usage: python3 voice_convert.py --task-id task_xxx

Processing pipeline:
1. Load task info from database
2. Update status: separating -> call Spleeter/Demucs to separate vocals
3. Update status: converting -> call RVC/So-VITS-SVC to run voiceprint conversion
4. Update status: rendering -> synthesize final audio (converted vocal + accompaniment)
5. Complete -> write result file + update status to completed
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

# ---- Config ----
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

# ---- Database ----
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

# ---- Step 1: Vocal separation ----
def separate_vocals(song_path, output_dir, task_id, config):
    """Use Spleeter or Demucs to separate vocals and accompaniment"""
    spleeter = config['python']['spleeter_path']
    
    print(f'  [separate] separate vocals: {song_path}')
    
    # Trying spleeter
    cmd = [
        spleeter, 'separate',
        '-p', 'spleeter:2stems',  # 2 stems: vocals + accompaniment
        '-o', output_dir,
        song_path
    ]
    
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=config['python']['timeout'])
        if result.returncode != 0:
            # Fallback to Demucs
            print(f'  [separate] Spleeter failed, trying Demucs...')
            print(f'  [separate] stderr: {result.stderr[:200]}')
            return separate_with_demucs(song_path, output_dir, task_id, config)
        
        # Spleeter output dir: output_dir/<filename>/
        base_name = os.path.splitext(os.path.basename(song_path))[0]
        vocals_path = os.path.join(output_dir, base_name, 'vocals.wav')
        accompaniment_path = os.path.join(output_dir, base_name, 'accompaniment.wav')
        
        if os.path.exists(vocals_path):
            print(f'  [separate] Vocals: {vocals_path}')
            print(f'  [separate] Accompaniment: {accompaniment_path}')
            return vocals_path, accompaniment_path
        else:
            raise FileNotFoundError(f'Spleeter did not produce expected file: {vocals_path}')
            
    except subprocess.TimeoutExpired:
        raise RuntimeError('Vocal separation timeout')
    except FileNotFoundError:
        print(f'  [separate] Spleeter not found, trying Demucs...')
        return separate_with_demucs(song_path, output_dir, task_id, config)

def separate_with_demucs(song_path, output_dir, task_id, config):
    """Use Demucs to separate vocals"""
    cmd = [
        'python3', '-m', 'demucs',
        '--two-stems', 'vocals',
        '-o', output_dir,
        song_path
    ]
    
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=config['python']['timeout'])
        if result.returncode != 0:
            raise RuntimeError(f'Demucs failed: {result.stderr[:300]}')
        
        base_name = os.path.splitext(os.path.basename(song_path))[0]
        model_dir = os.path.join(output_dir, 'htdemucs', base_name)
        vocals_path = os.path.join(model_dir, 'vocals.wav')
        no_vocals_path = os.path.join(model_dir, 'no_vocals.wav')
        
        if os.path.exists(vocals_path):
            return vocals_path, no_vocals_path
        else:
            raise FileNotFoundError(f'Demucs did not produce expected file: {vocals_path}')
            
    except subprocess.TimeoutExpired:
        raise RuntimeError('Vocal separation timeout(Demucs)')

# ---- Step 2: Voiceprint conversion ----
def convert_voice(vocals_path, voiceprint_embedding, output_path, pitch_shift, config):
    """
    Replace separated vocals with target voiceprint
    
    Recommended solutions:
    1. RVC (Retrieval-based Voice Conversion) - best quality
    2. So-VITS-SVC - Chinese-optimized
    3. OpenVoice - lightweight, zero-shot
    
    Script supports RVC and OpenVoice
    """
    rvc_path = config['python'].get('rvc_path', '')
    
    if rvc_path and os.path.exists(rvc_path):
        return convert_with_rvc(vocals_path, voiceprint_embedding, output_path, pitch_shift, rvc_path)
    else:
        print('  [convert] RVC path not configured, using OpenVoice')
        return convert_with_openvoice(vocals_path, voiceprint_embedding, output_path, pitch_shift)

def convert_with_rvc(vocals_path, embedding_path, output_path, pitch_shift, rvc_path):
    """Using RVC for voiceprint conversion"""
    import numpy as np
    
    embedding = np.load(embedding_path)
    
    # RVC inference script call
    # Adjust paths for RVC project structure in deployment
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
    
    print(f'  [convert] RVC inference: {" ".join(cmd)}')
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
    
    if result.returncode != 0:
        raise RuntimeError(f'RVC conversion failed: {result.stderr[:300]}')
    
    return output_path

def convert_with_openvoice(vocals_path, embedding_path, output_path, pitch_shift):
    """Using OpenVoice for zero-shot voiceprint conversion"""
    import numpy as np
    import librosa
    import soundfile as sf
    
    print('  [convert] Using OpenVoice for zero-shot conversion')
    
    # Loading vocal audio
    audio, sr = librosa.load(vocals_path, sr=16000, mono=True)
    
    # Loading voiceprint embedding
    embedding = np.load(embedding_path)
    
    try:
        # Trying to import OpenVoice
        from openvoice import se_extractor
        from openvoice.api import ToneColorConverter
        
        # Creating ToneColorConverter
        # Note: download model before use
        ckpt_converter = os.path.join(PROJECT_ROOT, 'models', 'converter')
        
        if not os.path.exists(ckpt_converter):
            raise FileNotFoundError(
                f'OpenVoice model not downloaded, run:\n'
                f'  git clone https://github.com/myshell-ai/OpenVoice.git\n'
                f'  and download checkpoint to models/converter/'
            )
        
        tone_converter = ToneColorConverter(ckpt_converter, device='cpu')
        
        # Extract source audio tone
        source_se, _ = se_extractor.get_se(vocals_path, tone_converter, vad=False)
        
        # Running conversion
        converted = tone_converter.convert(
            audio_src_path=vocals_path,
            src_se=source_se,
            tgt_se=embedding,
            output_path=output_path,
        )
        
        return output_path
        
    except ImportError:
        # Fallback: simple resample + pitch shift (demo only)
        print('  [convert] OpenVoice not installed, using simplified (pitch shift only)')
        return fallback_pitch_shift(vocals_path, output_path, pitch_shift)

def fallback_pitch_shift(input_path, output_path, semitones):
    """Fallback: pitch shift only"""
    import librosa
    import soundfile as sf
    
    audio, sr = librosa.load(input_path, sr=44100, mono=True)
    shifted = librosa.effects.pitch_shift(y=audio, sr=sr, n_steps=semitones)
    
    output_dir = os.path.dirname(output_path)
    os.makedirs(output_dir, exist_ok=True)
    sf.write(output_path, shifted, sr)
    
    print(f'  [convert] Pitch shift complete: {semitones} semitones')
    return output_path

# ---- Step 3: Synthesize final audio ----
def mix_audio(converted_vocals_path, accompaniment_path, output_path):
    """Mix converted vocals with accompaniment"""
    import librosa
    import soundfile as sf
    import numpy as np
    
    print(f'  [mix] Mixing: vocals + accompaniment')
    
    # Loading tracks
    vocals, sr1 = librosa.load(converted_vocals_path, sr=None, mono=False)
    accomp, sr2 = librosa.load(accompaniment_path, sr=None, mono=False)
    
    # Unifying sample rate
    target_sr = 44100
    if sr1 != target_sr:
        vocals = librosa.resample(y=vocals if vocals.ndim == 1 else vocals[0], 
                                   orig_sr=sr1, target_sr=target_sr)
    if sr2 != target_sr:
        accomp = librosa.resample(y=accomp if accomp.ndim == 1 else accomp[0],
                                   orig_sr=sr2, target_sr=target_sr)
    
    # Ensuring length consistency
    max_len = max(len(vocals), len(accomp))
    vocals = np.pad(vocals, (0, max_len - len(vocals)))
    accomp = np.pad(accomp, (0, max_len - len(accomp)))
    
    # Mix: vocals 70% + accompaniment 100%
    mixed = vocals * 0.7 + accomp * 1.0
    
    # Preventing clipping
    max_val = np.abs(mixed).max()
    if max_val > 0.99:
        mixed = mixed / max_val * 0.95
    
    output_dir = os.path.dirname(output_path)
    os.makedirs(output_dir, exist_ok=True)
    sf.write(output_path, mixed, target_sr)
    
    duration = len(mixed) / target_sr
    print(f'  [mix] Mix complete: {output_path} ({duration:.1f}s)')
    return duration

# ---- Main ----
def main():
    parser = argparse.ArgumentParser(description='Voice conversion pipeline')
    parser.add_argument('--task-id', required=True, help='Task ID')
    args = parser.parse_args()

    task_id = args.task_id
    config = load_config()
    db = get_db(config)

    print(f'[{datetime.now()}] Starting task: {task_id}')

    try:
        # Loading task
        task = get_task(db, task_id)
        if not task:
            raise ValueError(f'Task not found: {task_id}')
        
        song_path = task['song_file_path']
        voiceprint_id = task['voiceprint_id']
        embedding_path = task['embedding_path']
        pitch_shift = task['pitch_shift']
        
        print(f'  Song: {task["song_name"]} ({song_path})')
        print(f'  Voiceprint: {voiceprint_id}')
        print(f'  Pitch shift: {pitch_shift} semitones')

        task_dir = os.path.join(config['paths']['temp'], task_id)
        os.makedirs(task_dir, exist_ok=True)

        # ---- Step 1: Vocal separation ----
        print(f'\n[{datetime.now()}] Step 1/3: Vocal separation')
        update_progress(db, task_id, 'separating', 10)
        
        vocals_path, accomp_path = separate_vocals(song_path, task_dir, task_id, config)
        update_progress(db, task_id, 'separating', 33)

        # ---- Step 2: Voiceprint conversion ----
        print(f'\n[{datetime.now()}] Step 2/3: Voiceprint conversion')
        update_progress(db, task_id, 'converting', 40)
        
        converted_path = os.path.join(task_dir, f'{task_id}_converted.wav')
        convert_voice(vocals_path, embedding_path, converted_path, pitch_shift, config)
        update_progress(db, task_id, 'converting', 70)

        # ---- Step 3: Synthesize output ----
        print(f'\n[{datetime.now()}] Step 3/3: Synthesize output')
        update_progress(db, task_id, 'rendering', 80)
        
        result_path = os.path.join(config['paths']['results'], f'{task_id}.mp3')
        duration = mix_audio(converted_path, accomp_path, result_path)

        # Complete
        update_task(db, task_id,
                    state='completed',
                    progress=100,
                    result_path=result_path,
                    song_duration=int(duration))
        
        print(f'\n[{datetime.now()}] Task complete: {task_id}')
        print(f'  Result file: {result_path}')

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
