# Profile機能移行計画

## 概要

BEAR.Resourceで開発されたProfile関連クラス（XHProfResult、XdebugTrace、PhpProfile、Profile）をkoriym/semantic-loggerライブラリに移行し、汎用的なプロファイリング機能として提供する。

## 移行対象クラス

### 1. XHProfResult
- **機能**: XHProfプロファイリングデータの収集と管理
- **特徴**: 
  - start/stopライフサイクル管理
  - ファイルベースのデータ保存
  - JSON serialization対応
  - 統計情報の集計（関数数、実行時間等）

### 2. XdebugTrace
- **機能**: Xdebugトレースデータの収集と管理
- **特徴**:
  - graceful degradation（Xdebugが無効でもエラーにならない）
  - ファイルサイズと圧縮状態の監視
  - 設定の自動検証

### 3. PhpProfile
- **機能**: PHPバックトレースの収集と整理
- **特徴**:
  - フレームワーク内部呼び出しのフィルタリング
  - ユーザーコードに焦点を当てた情報収集
  - 設定可能なバックトレース深度

### 4. Profile
- **機能**: 上記3つのプロファイラーを統合管理
- **特徴**:
  - 複数のプロファイリング手法の組み合わせ
  - JSON出力での統合レポート

## 移行後のnamespace構造

```
Koriym\SemanticLogger\Profiler\
├── XHProfResult
├── XdebugTrace  
├── PhpProfile
└── Profile
```

## 移行戦略

### Phase 1: ライブラリ側への機能追加
1. src/Profiler/配下にクラス移行
2. namespace更新 (BEAR\Resource\SemanticLog\Profile → Koriym\SemanticLogger\Profiler)
3. 既存機能の保持（後方互換性重視）

### Phase 2: BEAR.Resource側の更新
1. import文の更新
2. 古いProfile関連クラスの削除
3. テストの更新

### Phase 3: 動作検証
1. BEAR.Resourceでのテスト実行
2. MCP serverとの連携確認
3. ドキュメント更新

## 設計原則

1. **後方互換性**: 既存のAPIを維持
2. **graceful degradation**: プロファイリング機能が利用できない環境でも動作
3. **schema準拠**: JSON出力はschema定義に従う
4. **汎用性**: BEAR.Resource以外でも利用可能

## 実装上の考慮点

- XHProf/Xdebugの有無を実行時に判定
- ファイルI/Oのエラーハンドリング
- メモリ使用量の最適化
- セキュリティ（ファイルパスの検証等）

## 関連ファイル

- `bin/php-dev.ini` - プロファイリング設定
- `src/DevLogger.php` - ログ出力機能
- `docs/schemas/profile.json` - Profile用スキーマ定義