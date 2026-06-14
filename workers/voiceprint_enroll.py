#!/usr/bin/env python3
# workers/voiceprint_enroll.py - Voiceprint feature extraction script
"""
Usage: python3 voiceprint_enroll.py --voiceprint-id vp_xxx --audio-file /path/to/audio.wav

Flow:
1. Load audio -> resample to 16kHz mono
2. Call SpeakerEncoder to extract voiceprint feature vector
3. Save feature vector to .npy file
4. Update database status to ready
"""

import argparse
import os
import sys
import json
import traceback
from datetime import datetime

# Project root (worker is in workers/ subdir)
PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, PROJECT_ROOT)

# ---- Config ----
def load_config():
    config_path = os.path.join(PROJECT_ROOT, 'config.php')
    # Parse PHP config as Python dict (or use env vars)
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

# ---- Database ----
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

# ---- Audio Processing ----
def preprocess_audio(audio_path, target_sr=16000):
    """Load and preprocess audio: resample to target_sr, convert to mono"""
    try:
        import librosa
        import soundfile as sf
    except ImportError:
        print('[ERROR] Please install: pip install librosa soundfile', file=sys.stderr)
        raise

    audio, sr = librosa.load(audio_path, sr=target_sr, mono=True)
    return audio, target_sr

# ---- Voiceprint Extraction ----
def extract_voiceprint(audio, sr=16000):
    """
    Extract voiceprint feature vector
    
    Options (ordered by ease of use):
    1. Resemblyzer (easiest) — pip install resemblyzer
    2. SpeechBrain ECAPA-TDNN (higher accuracy)
    3. WeSpeaker / CAM++ (Chinese-optimized)
    
    Using Resemblyzer as default
    """
    try:
        from resemblyzer import VoiceEncoder
    except ImportError:
        print('[ERROR] Please install Resemblyzer: pip install resemblyzer', file=sys.stderr)
        print('[INFO] Or install SpeechBrain: pip install speechbrain', file=sys.stderr)
        raise

    encoder = VoiceEncoder()
    # Extract embedding vector (256-dim)
    embedding = encoder.embed_utterance(audio)
    return embedding  # shape: (256,)

# ---- Main ----
def main():
    parser = argparse.ArgumentParser(description='Voiceprint feature extraction')
    parser.add_argument('--voiceprint-id', required=True, help='Voiceprint ID')
    parser.add_argument('--audio-file', required=True, help='Audio file path')
    args = parser.parse_args()

    voiceprint_id = args.voiceprint_id
    audio_path = args.audio_file

    print(f'[{datetime.now()}] Starting voiceprint extraction: {voiceprint_id}')
    print(f'  Audio file: {audio_path}')

    config = load_config()
    db = get_db(config)

    try:
        # Update status -> extracting
        update_voiceprint_status(db, voiceprint_id, 'extracting')
        print('  Status: extracting')

        # Preprocess
        audio, sr = preprocess_audio(audio_path)
        print(f'  Audio loaded: {len(audio)/sr:.1f}s, {sr}Hz')

        if len(audio) / sr < 3:
            raise ValueError(f'Audio too short ({len(audio)/sr:.1f}s)，need at least 3 seconds')

        # Extract features
        embedding = extract_voiceprint(audio, sr)
        print(f'  Feature extraction complete, dim: {embedding.shape}')

        # Save feature vector
        import numpy as np
        embedding_dir = os.path.join(config['paths']['voiceprints'], 'embeddings')
        os.makedirs(embedding_dir, exist_ok=True)
        embedding_path = os.path.join(embedding_dir, f'{voiceprint_id}.npy')
        np.save(embedding_path, embedding)
        print(f'  Feature saved: {embedding_path}')

        # Update database
        update_voiceprint_status(db, voiceprint_id, 'ready', embedding_path=embedding_path)
        print(f'[{datetime.now()}] Voiceprint extraction successful: {voiceprint_id}')

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
