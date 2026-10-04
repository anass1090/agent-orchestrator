declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            status: string | null;
            [key: string]: unknown;
        };
    }
}

export {};
