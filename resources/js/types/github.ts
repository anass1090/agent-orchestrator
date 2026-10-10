export type GithubConnection = {
    username: string;
    avatar_url: string | null;
    connected_at: string | null;
};

export type GithubRepository = {
    full_name: string;
    private: boolean;
    default_branch: string;
};
