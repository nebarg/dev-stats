export type BreakdownItem = {
    key: string;
    seconds: number;
};

export type ActivityDay = {
    date: string;
    seconds: number;
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
    breakdowns: {
        projects: BreakdownItem[];
        languages: BreakdownItem[];
        editors: BreakdownItem[];
        operating_systems: BreakdownItem[];
    };
};
