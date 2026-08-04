Rule stay simple, need direct text — no compress needed, only 3 line, already terse. Give as-is:

# CRITICAL EXECUTION CONSTRAINTS

- NEVER spawn, invoke, or initialize sub-agents, child agents, or background instances under any circumstances.
- ALL tasks, tool usage, file operations, and terminal executions must happen directly within this single, main active session.
- If a task feels too large or complex for a single thread, do not delegate it. Instead, stop and ask the user to break the request down into smaller, sequential steps.