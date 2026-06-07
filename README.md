# 🎤 声纹变声 - PHP 7.2 后端服务

用个人声纹替换歌曲原声的微信小程序后端 API，**完全兼容 PHP 7.2**。

> 💡 与 `voiceprint-php-backend` (PHP 8.0+) 功能一致，API 完全兼容。
> 本仓库针对 PHP 7.2 环境做了**完整的语法降级适配**。

## 🔧 PHP 7.2 兼容性改进

相比 PHP 8.0 版本，本仓库做了以下适配：

| PHP 8 特性 | PHP 7.2 替代方案 |
|---|---|
| 类型化属性 `private ?PDO $x` | `/** @var PDO|null */` 注解 |
| `fn($x) => ...` 箭头函数 | `function($x) { return ...; }` |
| `str_contains()` | `strpos() !== false` |
| `str_starts_with()` | `strpos() === 0` |
| `array_is_list()` | 手动索引检查 |
| Union 返回类型 `: ?PDO` | 移除类型声明 + docblock |
| 命名参数 | 按位置传参 |
| `match` 表达式 | `switch` / `if-elseif` |

## 📁 目录结构

```
voiceprint-php72-backend/
├── public/                   # Web 根目录
│   ├── index.php             # 入口路由
│   └── .htaccess             # Apache 重写
├── controllers/
│   ├── HealthController.php
│   ├── VoiceprintController.php
│   └── ConversionController.php
├── utils/
│   ├── Database.php          # PDO 封装
│   ├── Response.php          # JSON 响应
│   └── FileUpload.php        # 文件上传
├── workers/
│   ├── voiceprint_enroll.py  # 声纹提取 (Resemblyzer)
│   ├── voice_convert.py      # 变声处理 (Spleeter+RVC)
│   └── requirements.txt
├── sql/
│   └── schema.sql            # MySQL 建表
├── config.php                # 配置文件
├── uploads/ / results/ / logs/
└── README.md
```

## 🚀 部署 (PHP 7.2 + Apache)

### 1. 环境要求

| 组件 | 最低版本 |
|------|---------|
| PHP | **7.2** |
| MySQL | 5.7 / MariaDB 10.2 |
| Python | 3.7+ |
| Apache | 2.4 (+ mod_rewrite) |

### 2. 安装 PHP 扩展

```bash
# Debian/Ubuntu
sudo apt install php7.2 php7.2-mysql php7.2-mbstring php7.2-fileinfo

# CentOS 7
sudo yum install php72w php72w-mysql php72w-mbstring php72w-fileinfo
```

### 3. 数据库初始化

```bash
mysql -u root -p < sql/schema.sql
```

### 4. Python 依赖

```bash
cd workers
pip install -r requirements.txt
```

### 5. 配置

编辑 `config.php`，修改数据库密码和 API 域名：

```php
return array(
    'db' => array(
        'host'     => '127.0.0.1',
        'database' => 'voiceprint_converter',
        'username' => 'root',
        'password' => 'your_password',
    ),
    'result_base_url' => 'https://your-domain.com/results',
);
```

### 6. Apache 配置

```apache
<VirtualHost *:80>
    ServerName api.example.com
    DocumentRoot /var/www/html/voiceprint-php72-backend/public
    
    <Directory /var/www/html/voiceprint-php72-backend/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    # 结果文件公共访问
    Alias /results /var/www/html/voiceprint-php72-backend/results
    <Directory /var/www/html/voiceprint-php72-backend/results>
        Require all granted
    </Directory>
</VirtualHost>
```

### 7. 权限

```bash
sudo chown -R www-data:www-data uploads/ results/ logs/
sudo chmod -R 755 uploads/ results/ logs/
```

### 8. 重启

```bash
sudo systemctl restart apache2
```

## 📡 API (与 PHP 8 版本完全兼容)

```
GET  /v1/health
POST /v1/voiceprint/enroll      multipart: voice_sample, duration
POST /v1/conversion/submit      multipart: song_file, voiceprintId, songName, pitchShift
GET  /v1/conversion/status      ?taskId=xxx
GET  /v1/conversion/result      ?taskId=xxx
POST /v1/conversion/delete      JSON: {"taskId":"xxx"}
GET  /v1/conversion/history     ?page=1&pageSize=20
```

响应格式统一: `{"code":0,"message":"ok","data":{...}}`

## ⚠️ PHP 7.2 注意事项

1. **JSON_INVALID_UTF8_IGNORE** 常量在 PHP 7.2 中不可用，已在代码中避免
2. **random_bytes()** PHP 7.0 起可用，已添加 fallback 到 `md5()+mt_rand()`
3. **glob()** 返回值在 PHP 7.2 中可能为 `false`，已做空值检查
4. 不依赖任何 Composer 包，纯原生 PHP 实现

## 📄 License

MIT
