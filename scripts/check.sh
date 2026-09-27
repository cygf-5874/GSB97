#!/usr/bin/env bash
# 固定验收入口的薄封装：把参数原样转给仓库根的 check/check.php。
#
#   bash scripts/check.sh                  跑全部 11 个场景
#   bash scripts/check.sh -list            列出全部场景
#   bash scripts/check.sh --only upcast    只跑一组
#   bash scripts/check.sh --only defer,state
set -u

cd "$(dirname "$0")/.." || exit 2

if ! command -v php >/dev/null 2>&1; then
  echo "需要 PHP 8（找不到 php）" >&2
  exit 2
fi

exec php check/check.php "$@"
