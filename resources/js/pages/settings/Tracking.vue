<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import ApiKeyController from '@/actions/App/Http/Controllers/Settings/ApiKeyController';
import TrackingController from '@/actions/App/Http/Controllers/Settings/TrackingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/tracking';

const props = defineProps<{
    timezone: string;
    heartbeatTimeoutSec: number;
    timezones: string[];
    apiKey: string;
    apiUrl: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Tracking settings',
                href: edit(),
            },
        ],
    },
});

const timeoutOptions = [
    { value: '60', label: '1 minute' },
    { value: '120', label: '2 minutes' },
    { value: '300', label: '5 minutes' },
    { value: '600', label: '10 minutes' },
    { value: '900', label: '15 minutes' },
    { value: '1200', label: '20 minutes' },
    { value: '1800', label: '30 minutes' },
    { value: '3600', label: '1 hour' },
];

const timeoutValue = String(props.heartbeatTimeoutSec);

if (!timeoutOptions.some((option) => option.value === timeoutValue)) {
    timeoutOptions.push({
        value: timeoutValue,
        label: `${props.heartbeatTimeoutSec} seconds`,
    });
}

const isCopied = ref(false);

async function copyApiKey() {
    await navigator.clipboard.writeText(props.apiKey);
    isCopied.value = true;
    setTimeout(() => (isCopied.value = false), 2000);
}

const config = `[settings]
api_url = ${props.apiUrl}
api_key = ${props.apiKey}`;
</script>

<template>
    <Head title="Tracking settings" />

    <h1 class="sr-only">Tracking settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Tracking"
            description="Control how your coding time is measured"
        />

        <Form
            v-bind="TrackingController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="timezone">Timezone</Label>
                <Select name="timezone" :default-value="timezone">
                    <SelectTrigger id="timezone" class="w-full">
                        <SelectValue placeholder="Select a timezone" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="zone in timezones"
                            :key="zone"
                            :value="zone"
                        >
                            {{ zone }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-sm text-muted-foreground">
                    Sets where your days begin and end — daily totals, streaks
                    and the activity chart all follow it.
                </p>
                <InputError class="mt-2" :message="errors.timezone" />
            </div>

            <div class="grid gap-2">
                <Label for="heartbeat_timeout_sec">Keystroke timeout</Label>
                <Select
                    name="heartbeat_timeout_sec"
                    :default-value="timeoutValue"
                >
                    <SelectTrigger id="heartbeat_timeout_sec" class="w-full">
                        <SelectValue placeholder="Select a timeout" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in timeoutOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-sm text-muted-foreground">
                    A pause longer than this ends the coding session. Changing
                    it recalculates your existing sessions.
                </p>
                <InputError
                    class="mt-2"
                    :message="errors.heartbeat_timeout_sec"
                />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-tracking-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="API key"
            description="Authenticates the WakaTime plugins and CLI against this server"
        />

        <div class="grid gap-2">
            <Label for="api_key">Your API key</Label>
            <div class="flex items-center gap-2">
                <Input
                    id="api_key"
                    class="block w-full font-mono"
                    :model-value="apiKey"
                    readonly
                />
                <Button
                    type="button"
                    variant="outline"
                    data-test="copy-api-key-button"
                    @click="copyApiKey"
                >
                    {{ isCopied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="wakatime_cfg">Add to ~/.wakatime.cfg</Label>
            <pre
                id="wakatime_cfg"
                class="overflow-x-auto rounded-lg border bg-muted p-4 text-sm"
                >{{ config }}</pre>
        </div>

        <Form v-bind="ApiKeyController.update.form()" v-slot="{ processing }">
            <div class="flex flex-col gap-2">
                <div>
                    <Button
                        variant="outline"
                        :disabled="processing"
                        data-test="regenerate-api-key-button"
                    >
                        Generate new key
                    </Button>
                </div>
                <p class="text-sm text-muted-foreground">
                    The current key stops working immediately, so update every
                    machine's ~/.wakatime.cfg afterwards.
                </p>
            </div>
        </Form>
    </div>
</template>
