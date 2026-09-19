#!/bin/bash
# tmux switch-client only affects an already-attached client. The browser
# needs a moment to load ttyd and attach after the redirect, so retry twice
# with a delay instead of firing once immediately.

TTYD_SESSION="$1"
TARGET="$2"
if [ -z "$TTYD_SESSION" ] || [ -z "$TARGET" ]; then
    echo "usage: switch-terminal-finish.sh <ttyd-tmux-session> <target-session>" >&2
    exit 1
fi

sleep 2
tmux send-keys -t "$TTYD_SESSION" "tmux switch-client -t $TARGET" Enter
sleep 3
tmux send-keys -t "$TTYD_SESSION" "tmux switch-client -t $TARGET" Enter
