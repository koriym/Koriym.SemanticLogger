#!/bin/bash

echo "=== MCP Server Debug Script ==="
echo

echo "1. Checking for semantic log files in /tmp..."
ls -la /tmp/semantic-dev-*.json 2>/dev/null || echo "No log files found in /tmp"
echo

echo "2. Testing MCP server manually..."
echo "Testing initialize..."
echo '{"jsonrpc":"2.0","method":"initialize","id":1}' | php bin/server.php /tmp 2>/dev/null | jq .
echo

echo "Testing tools/list..."
echo '{"jsonrpc":"2.0","method":"tools/list","id":1}' | php bin/server.php /tmp 2>/dev/null | jq .
echo

echo "Testing profile list..."
echo '{"jsonrpc":"2.0","method":"tools/call","id":1,"params":{"name":"profile","arguments":{"list":true}}}' | php bin/server.php /tmp 2>/dev/null | jq -r '.result.content[0].text'
echo

echo "3. Claude Desktop config:"
cat ~/Library/Application\ Support/Claude/claude_desktop_config.json 2>/dev/null || echo "Config file not found"
echo

echo "=== Debug Complete ==="