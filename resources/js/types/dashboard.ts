export type BreakdownItem = {
    key: string;
    seconds: number;
};

export type ActivityDay = {
    date: string;
    seconds: number;
};

export type EditingStats = {
    write_events: number;
    read_events: number;
    agent_write_events: number;
    agent_lines: number;
};

export type AgentStats = {
    key: string;
    lines: number;
    input_tokens: number;
    output_tokens: number;
    sessions: number;
};

export type AiStats = {
    ai_lines: number;
    human_lines: number;
    input_tokens: number;
    output_tokens: number;
    sessions: number;
    prompts: number;
    avg_prompt_length: number;
    agents: AgentStats[];
};

export type FocusStats = {
    longest_block_seconds: number;
    deep_work_seconds: number;
    deep_work_blocks: number;
    context_switches: number;
};

export type StreakStats = {
    current_days: number;
    longest_days: number;
};

export type DashboardStats = {
    range: string;
    ranges: string[];
    from: string;
    to: string;
    total_seconds: number;
    today_seconds: number;
    daily_average_seconds: number;
    active_days: number;
    most_active_day: { date: string; seconds: number } | null;
    activity: ActivityDay[];
    focus: FocusStats;
    streak: StreakStats;
    editing: EditingStats;
    ai: AiStats;
    breakdowns: {
        projects: BreakdownItem[];
        languages: BreakdownItem[];
        editors: BreakdownItem[];
        operating_systems: BreakdownItem[];
        categories: BreakdownItem[];
    };
};
