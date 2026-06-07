-- sql/schema.sql - 数据库初始化 (MySQL 5.7+ / MariaDB 10.2+)
CREATE DATABASE IF NOT EXISTS voiceprint_converter
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE voiceprint_converter;

CREATE TABLE IF NOT EXISTS voiceprints (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    voiceprint_id   VARCHAR(64)     NOT NULL UNIQUE COMMENT '声纹唯一ID',
    file_path       VARCHAR(500)    NOT NULL COMMENT '录音文件路径',
    file_name       VARCHAR(255)    NOT NULL COMMENT '原始文件名',
    file_size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '时长(秒)',
    embedding_path  VARCHAR(500)    NULL COMMENT '特征向量路径(.npy)',
    status          ENUM('pending','extracting','ready','failed')
                                    NOT NULL DEFAULT 'pending',
    error_message   TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversion_tasks (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id             VARCHAR(64)     NOT NULL UNIQUE,
    voiceprint_id       VARCHAR(64)     NOT NULL,
    song_file_path      VARCHAR(500)    NOT NULL,
    song_name           VARCHAR(255)    NOT NULL,
    song_original_name  VARCHAR(255)    NOT NULL,
    song_size           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    song_duration       INT UNSIGNED    NOT NULL DEFAULT 0,
    pitch_shift         INT             NOT NULL DEFAULT 0 COMMENT '音调偏移(半音)',
    result_path         VARCHAR(500)    NULL,
    state               ENUM('pending','separating','converting','rendering','completed','failed')
                                        NOT NULL DEFAULT 'pending',
    progress            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error_message       TEXT            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_voiceprint (voiceprint_id),
    INDEX idx_state (state),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
