import { Head, Link } from '@inertiajs/react';

export default function LegalTerms({ version }: { version: string }) {
    return (
        <>
            <Head title="Terms of service" />
            <div className="mx-auto max-w-2xl px-4 py-12">
                <p className="text-xs text-muted-foreground">
                    Version {version}
                </p>
                <h1 className="mt-2 font-serif text-xl font-medium tracking-tight">
                    Terms of service
                </h1>
                <p className="mt-4 text-sm leading-relaxed text-muted-foreground">
                    Placeholder page. Replace with your full terms or link to a
                    hosted document.
                </p>
                <Link
                    href="/"
                    className="mt-8 inline-block text-sm text-primary underline"
                >
                    Back home
                </Link>
            </div>
        </>
    );
}
