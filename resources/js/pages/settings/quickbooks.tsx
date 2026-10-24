import { Head, router } from '@inertiajs/react';
import { Plus, RefreshCw, Unplug } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { connect, destroy, index } from '@/routes/quickbooks';
import type { QuickBooksConnection } from '@/types';

type Props = {
    connections: QuickBooksConnection[];
    teamName: string;
    canManage: boolean;
    configured: boolean;
    environment: string;
    redirectUri: string;
    mcpUrl: string;
    mcpTokenConfigured: boolean;
    projectPath: string;
};

export default function QuickBooks({
    connections,
    teamName,
    canManage,
    configured,
    environment,
    redirectUri,
    mcpUrl,
    mcpTokenConfigured,
    projectPath,
}: Props) {
    const [disconnecting, setDisconnecting] =
        useState<QuickBooksConnection | null>(null);
    const [processing, setProcessing] = useState(false);

    const disconnect = () => {
        if (!disconnecting) {
            return;
        }

        router.delete(destroy.url(disconnecting.slug), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setDisconnecting(null),
        });
    };

    const localConfig = JSON.stringify(
        {
            mcpServers: {
                quickbooks: {
                    command: 'php',
                    args: [`${projectPath}/artisan`, 'mcp:start', 'quickbooks'],
                },
            },
        },
        null,
        2,
    );

    return (
        <>
            <Head title="QuickBooks" />

            <h1 className="sr-only">QuickBooks</h1>

            <div className="flex flex-col space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="QuickBooks companies"
                        description={`Companies connected to ${teamName}. Claude can work with all of them through one connector.`}
                    />

                    {canManage && (
                        <Button asChild disabled={!configured}>
                            <a href={connect.url()}>
                                <Plus /> Connect company
                            </a>
                        </Button>
                    )}
                </div>

                {!configured && (
                    <Alert>
                        <AlertTitle>Intuit app credentials missing</AlertTitle>
                        <AlertDescription>
                            Set QUICKBOOKS_CLIENT_ID and
                            QUICKBOOKS_CLIENT_SECRET in your .env file, and
                            register{' '}
                            <code className="rounded bg-muted px-1">
                                {redirectUri}
                            </code>{' '}
                            as a redirect URI in your Intuit developer app.
                        </AlertDescription>
                    </Alert>
                )}

                {connections.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No companies connected yet.
                    </p>
                ) : (
                    <div className="space-y-3">
                        {connections.map((connection) => (
                            <div
                                key={connection.slug}
                                data-test="quickbooks-connection-row"
                                className="flex items-center justify-between gap-4 rounded-lg border p-4"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {connection.companyName}
                                        </span>
                                        <Badge variant="secondary">
                                            {connection.slug}
                                        </Badge>
                                        {connection.needsReconnect && (
                                            <Badge variant="destructive">
                                                Reconnect required
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Realm {connection.realmId}
                                        {connection.connectedBy
                                            ? ` · connected by ${connection.connectedBy}`
                                            : ''}
                                        {' · '}
                                        {connection.needsReconnect
                                            ? 'expired'
                                            : `valid until ${new Date(connection.expiresAt).toLocaleDateString()}`}
                                    </p>
                                </div>

                                {canManage && (
                                    <div className="flex shrink-0 items-center gap-2">
                                        {connection.needsReconnect && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <a href={connect.url()}>
                                                    <RefreshCw /> Reconnect
                                                </a>
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            data-test="quickbooks-disconnect-button"
                                            onClick={() =>
                                                setDisconnecting(connection)
                                            }
                                        >
                                            <Unplug /> Disconnect
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                <div className="space-y-4 border-t pt-6">
                    <Heading
                        variant="small"
                        title="Connect Claude"
                        description={`Environment: ${environment}. One MCP server exposes every company above; tools take a "company" argument.`}
                    />

                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            Claude Code (local, no token needed)
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Add this to your project or global .mcp.json:
                        </p>
                        <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs">
                            {localConfig}
                        </pre>
                    </div>

                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            claude.ai or remote Claude Code
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Point a custom connector at{' '}
                            <code className="rounded bg-muted px-1">
                                {mcpUrl}
                            </code>{' '}
                            with the header{' '}
                            <code className="rounded bg-muted px-1">
                                Authorization: Bearer &lt;MCP_ACCESS_TOKEN&gt;
                            </code>
                            .
                            {!mcpTokenConfigured &&
                                ' MCP_ACCESS_TOKEN is not set yet, so the remote endpoint is disabled.'}
                        </p>
                    </div>
                </div>
            </div>

            <Dialog
                open={disconnecting !== null}
                onOpenChange={(open) => !open && setDisconnecting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Disconnect company</DialogTitle>
                        <DialogDescription>
                            Claude will no longer be able to access{' '}
                            <strong>{disconnecting?.companyName}</strong>. You
                            can connect it again at any time.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>

                        <Button
                            variant="destructive"
                            data-test="quickbooks-disconnect-confirm"
                            disabled={processing}
                            onClick={disconnect}
                        >
                            Disconnect
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

QuickBooks.layout = {
    breadcrumbs: [
        {
            title: 'QuickBooks',
            href: index(),
        },
    ],
};
