export type QuickBooksConnection = {
    slug: string;
    companyName: string;
    realmId: string;
    connectedBy: string | null;
    connectedAt: string;
    expiresAt: string;
    needsReconnect: boolean;
};
