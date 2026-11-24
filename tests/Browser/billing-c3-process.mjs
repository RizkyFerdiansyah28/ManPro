import { spawn } from 'node:child_process';
import { performance } from 'node:perf_hooks';

// Timeout reports failure and leaves the handle to the Windows Job supervisor.
// This driver never kills by PID or retries a command after its outcome is unknown.
export function runC3Process(executable, args, options, milliseconds, input = '') {
    return new Promise((resolve, reject) => {
        const deadline = performance.now() + milliseconds;
        const child = spawn(executable, args, { ...options, windowsHide: true, stdio: ['pipe', 'pipe', 'ignore'] });
        const chunks = [];
        let bytes = 0;
        let finished = false;
        const finish = error => {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            if (error) {
                child.stdin.destroy();
                child.stdout.destroy();
                child.unref();
                reject(error);
            } else {
                resolve(Buffer.concat(chunks).toString('utf8'));
            }
        };
        const timer = setTimeout(() => finish(new Error('billing_c3_node_child_deadline_exceeded')), milliseconds);
        child.on('error', () => finish(new Error('billing_c3_node_child_launch_failed')));
        child.stdin.on('error', () => finish(new Error('billing_c3_node_child_input_failed')));
        child.stdout.on('data', chunk => {
            bytes += chunk.length;
            if (bytes > 262144) finish(new Error('billing_c3_node_child_output_limit'));
            else chunks.push(chunk);
        });
        child.on('close', (code, signal) => {
            if (performance.now() >= deadline) finish(new Error('billing_c3_node_child_deadline_exceeded'));
            else finish(code === 0 && signal === null ? null : new Error('billing_c3_node_child_exit_unverified'));
        });
        child.stdin.end(input);
    });
}

export async function withinC3Deadline(action, milliseconds) {
    let timer;
    const deadline = performance.now() + milliseconds;
    try {
        const result = await Promise.race([
            action(),
            new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('billing_c3_node_deadline_exceeded')), milliseconds); }),
        ]);
        if (performance.now() >= deadline) throw new Error('billing_c3_node_deadline_exceeded');
        return result;
    } finally {
        clearTimeout(timer);
    }
}
