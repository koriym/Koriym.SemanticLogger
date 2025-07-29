<?php

require_once 'vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;

// データベースクエリのコンテキスト
final class DatabaseQueryContext extends AbstractContext
{
    public const TYPE = 'database_query';
    public const SCHEMA_URL = 'https://example.com/schemas/database_query.json';
    
    public function __construct(
        public readonly string $query,
        public readonly array $params,
        public readonly string $table,
    ) {
    }
}

// ログセッションの開始 - 関連情報をrelで指定
$logger = new SemanticLogger();

// データベースクエリ開始
$logger->open(new DatabaseQueryContext(
    'SELECT * FROM users WHERE status = ?', 
    ['active'],
    'users'
));

$logger->close(new class extends AbstractContext {
    public const TYPE = 'query_success';
    public const SCHEMA_URL = 'https://example.com/schemas/query_success.json';
    
    public function __construct(
        public readonly int $rowCount = 5,
        public readonly float $executionTimeMs = 12.5,
    ) {}
});

// 完全な透明性のための関連情報を追加
$relations = [
    [
        'rel' => 'schema',
        'href' => 'https://example.com/db/schema/users.sql',
        'title' => 'Users Table Schema',
        'type' => 'application/sql'
    ],
    [
        'rel' => 'source',
        'href' => 'https://github.com/example/app/blob/main/src/UserRepository.php#L42',
        'title' => 'Source Code Location',
        'type' => 'text/x-php'
    ],
    [
        'rel' => 'documentation',
        'href' => 'https://docs.example.com/api/users-query',
        'title' => 'API Documentation',
        'type' => 'text/html'
    ],
    [
        'rel' => 'metrics',
        'href' => 'https://monitoring.example.com/queries/users',
        'title' => 'Query Performance Metrics',
        'type' => 'application/json'
    ],
    [
        'rel' => 'environment',
        'href' => 'https://config.example.com/production/database',
        'title' => 'Database Configuration',
        'type' => 'application/yaml'
    ]
];

// relationsを含むLogJsonを作成
$logData = $logger->flush();
$enrichedLog = new LogJson(
    $logData->schemaUrl,
    $logData->open,
    $logData->events,
    $logData->close,
    $relations
);

echo json_encode($enrichedLog->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";