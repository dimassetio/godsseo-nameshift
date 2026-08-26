import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type NameserverPreset } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Presets({ presets }: { presets: NameserverPreset[] }) {
    const form = useForm({ name: '', nameservers: ['', ''] });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/settings/nameserver-presets', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Settings', href: '/settings' },
                { title: 'Nameserver presets', href: '/settings/nameserver-presets' },
            ]}
        >
            <Head title="Nameserver presets" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Nameserver presets" description="Save ordered nameserver sets for repeated changes." />
                    <Card>
                        <CardHeader>
                            <CardTitle>New preset</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label>Preset name</Label>
                                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                                </div>
                                <Nameservers values={form.data.nameservers} onChange={(nameservers) => form.setData('nameservers', nameservers)} />
                                <div className="flex gap-2">
                                    <Button disabled={form.processing}>Save preset</Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => form.setData('nameservers', [...form.data.nameservers, ''])}
                                    >
                                        Add nameserver
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                    <div className="grid gap-4 md:grid-cols-2">
                        {presets.map((preset) => (
                            <PresetCard key={preset.id} preset={preset} />
                        ))}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function PresetCard({ preset }: { preset: NameserverPreset }) {
    const form = useForm({ name: preset.name, nameservers: preset.nameservers });
    return (
        <Card>
            <CardHeader>
                <CardTitle>{preset.name}</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/settings/nameserver-presets/${preset.id}`, { preserveScroll: true });
                    }}
                >
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <Nameservers values={form.data.nameservers} onChange={(nameservers) => form.setData('nameservers', nameservers)} />
                    <div className="flex gap-2">
                        <Button size="sm">Update</Button>
                        <Button
                            size="sm"
                            type="button"
                            variant="destructive"
                            onClick={() => router.delete(`/settings/nameserver-presets/${preset.id}`, { preserveScroll: true })}
                        >
                            Delete
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function Nameservers({ values, onChange }: { values: string[]; onChange: (values: string[]) => void }) {
    return (
        <div className="space-y-2">
            {values.map((value, index) => (
                <div className="flex gap-2" key={index}>
                    <Input
                        value={value}
                        placeholder={`ns${index + 1}.example.com`}
                        onChange={(e) => onChange(values.map((item, i) => (i === index ? e.target.value : item)))}
                    />
                    {values.length > 2 && (
                        <Button type="button" variant="outline" onClick={() => onChange(values.filter((_, i) => i !== index))}>
                            Remove
                        </Button>
                    )}
                </div>
            ))}
        </div>
    );
}
