# MCP Server Setup Guide

## 1. Installation

### Development Project (This Repository)
```bash
git clone https://github.com/koriym/semantic-logger
cd semantic-logger
composer install
cp mcp.example.json mcp.json
# Edit mcp.json paths as needed
```

### Other Projects (Composer Dependency)
```bash
composer require koriym/semantic-logger
```

## 2. Claude Desktop Configuration

Add the semantic profiler to your Claude Desktop config file:

**File location:**
- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

### For Development Project (This Repository)
```json
{
  "mcpServers": {
    "semantic-profiler": {
      "command": "php",
      "args": [
        "/Users/akihito/git/Koriym.SemanticLogger/bin/server.php",
        "/tmp",
        "Japanese"
      ]
    }
  }
}
```

### For Other Projects (Composer Dependency)
```json
{
  "mcpServers": {
    "semantic-profiler": {
      "command": "php",
      "args": [
        "vendor/koriym/semantic-logger/bin/server.php",
        "/tmp",
        "Japanese"
      ]
    }
  }
}
```

**Alternative with absolute paths:**
```json
{
  "mcpServers": {
    "semantic-profiler": {
      "command": "php",
      "args": [
        "/Users/username/myproject/vendor/koriym/semantic-logger/bin/server.php",
        "/tmp",
        "Japanese"
      ]
    }
  }
}
```

**Language Options:**
- `"Japanese"` - 日本語で分析結果を返す
- `"English"` - Return analysis results in English
- `"Korean"` - 한국어로 분석 결과 반환
- Any language supported by Claude

## 2. Using the /profile Command

Once configured, you can use the `/profile` command in Claude Desktop:

### Available Operations:

1. **Analyze Latest Log:**
   ```
   /profile
   ```

2. **Show Log History:**
   ```
   /profile list=true
   ```

3. **Analyze Specific Log:**
   ```
   /profile show=1
   ```
   (1=latest, 2=second latest, etc.)

4. **Execute Script and Analyze:**
   ```
   /profile file=my_script.php
   ```

## 3. Verification

After adding the configuration:

1. Restart Claude Desktop
2. Create a test semantic log by running PHP code that uses SemanticLogger
3. Try `/profile list=true` to see if logs are detected
4. Use `/profile` to analyze the latest log

## 4. Troubleshooting

**Common Issues:**

- **"No semantic log files found"**: Make sure the log directory exists and contains `semantic-dev-*.json` files
- **"Script not found"**: Use absolute paths for PHP scripts when using `/profile file=script.php`
- **Server not responding**: Check that PHP is available in PATH and the server.php file exists

**Debug Steps:**

1. Test server manually:
   ```bash
   echo '{"jsonrpc":"2.0","method":"initialize","id":1}' | php vendor/koriym/semantic-logger/bin/server.php /tmp
   ```

2. Check log directory has files:
   ```bash
   ls -la /tmp/semantic-dev-*.json
   ```