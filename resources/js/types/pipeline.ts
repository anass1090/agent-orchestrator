export type Step = {
    id: number;
    phase: string;
    attempt: number;
    status: string;
    error: string | null;
    exit_code: number | null;
    duration_ms: number;
    duration: string;
    cost_usd: number | null;
};

export type Run = {
    id: number;
    prompt: string;
    status: string;
    busy: boolean;
    can_stop: boolean;
    failed_check: string | null;
    stop_reason: string | null;
    error: string | null;
    branch: string | null;
    pushed: boolean;
    model: string | null;
    fix_attempts: number;
    max_fix_attempts: number;
    duration: string;
    cost_usd: number;
    tokens_in: number;
    tokens_out: number;
    started_at: string | null;
    created_at: string | null;
    project: { id: number; name: string };
    steps: Step[];
};

export type RunEvent = {
    sequence: number;
    phase: string;
    type: string;
    tool: string | null;
    summary: string | null;
    kind: string;
    glyph: string;
    offset: string;
    time: string;
    payload_on_disk: boolean;
};

export type Project = {
    id: number;
    name: string;
    path: string;
    branch: string;
    remote: string | null;
    runs_count: number;
    succeeded_count: number;
    failed_count: number;
    error_count: number;
    cost_usd: number;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
};
