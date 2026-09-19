#!/bin/bash
# Waits for Claude to initialise in a freshly-spawned tmux session, then sends
# /remote-control and a startup prompt. Adapted from ideas/scripts/start-planning.sh.
# Run backgrounded by spawn.php so the HTTP request doesn't hang for 60+ seconds.

SESSION="$1"
if [ -z "$SESSION" ]; then
    echo "usage: spawn-finish.sh <tmux-session-name>" >&2
    exit 1
fi

sleep 60
tmux send-keys -t "$SESSION" "/remote-control" Enter
sleep 5
tmux send-keys -t "$SESSION" "Read your CLAUDE.md and introduce yourself." Enter
