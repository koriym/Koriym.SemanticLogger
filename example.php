<?php

require_once 'vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\AbstractContext;

// APIリクエストのコンテキスト
final class ApiRequestContext extends AbstractContext
{
    public const TYPE = 'api_request';
    public const SCHEMA_URL = 'https://example.com/schemas/api_request.json';
    
    public function __construct(
        public readonly string $endpoint,
        public readonly string $method,
    ) {
    }
}

// データベースクエリのコンテキスト
final class DatabaseQueryContext extends AbstractContext
{
    public const TYPE = 'database_query';
    public const SCHEMA_URL = 'https://example.com/schemas/database_query.json';
    
    public function __construct(
        public readonly string $query,
        public readonly array $params,
    ) {
    }
}

// キャッシュミスのイベント
final class CacheMissContext extends AbstractContext
{
    public const TYPE = 'cache_miss';
    public const SCHEMA_URL = 'https://example.com/schemas/cache_miss.json';
    
    public function __construct(
        public readonly string $key,
        public readonly int $ttl,
    ) {
    }
}

// 成功結果
final class SuccessContext extends AbstractContext
{
    public const TYPE = 'success';
    public const SCHEMA_URL = 'https://example.com/schemas/api_success.json';
    
    public function __construct(
        public readonly int $userId,
        public readonly int $responseTimeMs,
    ) {
    }
}

// ログセッションの開始
$logger = new SemanticLogger();

// API リクエスト開始
$logger->open(new ApiRequestContext('/api/users/123', 'GET'));

// ネストされたデータベースクエリ開始  
$logger->open(new DatabaseQueryContext('SELECT * FROM users WHERE id = ?', [123]));

// イベント: キャッシュミス
$logger->event(new CacheMissContext('user_123', 3600));

// セッション終了
$logger->close(new SuccessContext(123, 45));

$logJson = $logger->flush();
echo json_encode($logJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";