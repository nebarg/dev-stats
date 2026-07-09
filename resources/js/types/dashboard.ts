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

export type CalendarHeatmapDay = {
    date: string;
    level: number;
    title: string;
};

export type AiCalendarDay = {
    date: string;
    ai_lines: number;
    human_lines: number;
};

export type WeekdayAverage = {
    label: string;
    average_seconds: number;
    ai_average_seconds: number;
};

export type LineTotals = {
    key: string;
    ai_lines: number;
    human_lines: number;
    path?: string;
    project?: string | null;
};

export type InsightsStats = {
    from: string;
    to: string;
    calendar: ActivityDay[];
    ai_calendar: AiCalendarDay[];
    weekdays: WeekdayAverage[];
    top_ai_projects: LineTotals[];
    top_human_projects: LineTotals[];
    top_ai_files: LineTotals[];
    top_human_files: LineTotals[];
};

export type FileStats = {
    key: string;
    path: string;
    seconds: number;
    ai_lines: number;
    human_lines: number;
};

export type ProjectStats = {
    project: string;
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
    files: FileStats[];
    file_count: number;
    breakdowns: {
        languages: BreakdownItem[];
        branches: BreakdownItem[];
        editors: BreakdownItem[];
        categories: BreakdownItem[];
    };
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
