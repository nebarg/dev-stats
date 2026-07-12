<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AiPricingController from '@/actions/App/Http/Controllers/Settings/AiPricingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/ai-pricing';

type AiPrice = {
    id: number;
    model_prefix: string;
    input_price: number;
    output_price: number;
    effective_from: string;
};

defineProps<{
    prices: AiPrice[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'AI pricing',
                href: edit(),
            },
        ],
    },
});

const today = new Date().toISOString().slice(0, 10);
</script>

<template>
    <Head title="AI pricing" />

    <h1 class="sr-only">AI pricing</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="AI pricing"
            description="Estimated USD per million tokens, used to price agent
            token usage. A price applies from its effective date until a newer
            one for the same model supersedes it."
        />

        <Form
            v-bind="AiPricingController.store.form()"
            reset-on-success
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-4 sm:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="model_prefix">Model</Label>
                    <Input
                        id="model_prefix"
                        name="model_prefix"
                        placeholder="opus/4.1"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="input_price">$ in / 1M</Label>
                    <Input
                        id="input_price"
                        name="input_price"
                        type="number"
                        step="any"
                        min="0"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="output_price">$ out / 1M</Label>
                    <Input
                        id="output_price"
                        name="output_price"
                        type="number"
                        step="any"
                        min="0"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="effective_from">Effective from</Label>
                    <Input
                        id="effective_from"
                        name="effective_from"
                        type="date"
                        :default-value="today"
                        required
                    />
                </div>
            </div>
            <InputError :message="errors.model_prefix" />
            <InputError :message="errors.input_price" />
            <InputError :message="errors.output_price" />
            <InputError :message="errors.effective_from" />
            <div>
                <Button :disabled="processing" data-test="add-price-button">
                    Add price
                </Button>
            </div>
        </Form>

        <p class="text-sm text-muted-foreground">
            Matching uses the longest model prefix, so an "opus/4.1" price beats
            an "opus" one for opus/4.1 heartbeats. Models without a price show
            no spend rather than $0.
        </p>

        <div class="flex flex-col gap-4">
            <p v-if="prices.length === 0" class="text-sm text-muted-foreground">
                No prices yet — agent spend stays unestimated until you add
                some.
            </p>

            <div
                v-for="price in prices"
                :key="price.id"
                class="flex items-end gap-2"
            >
                <Form
                    v-bind="AiPricingController.update.form(price.id)"
                    class="grid flex-1 gap-4 sm:grid-cols-4"
                    v-slot="{ processing }"
                >
                    <Input
                        :name="'model_prefix'"
                        :default-value="price.model_prefix"
                        :aria-label="`Model for price ${price.id}`"
                        required
                    />
                    <Input
                        :name="'input_price'"
                        type="number"
                        step="any"
                        min="0"
                        :default-value="String(price.input_price)"
                        :aria-label="`Input price for ${price.model_prefix}`"
                        required
                    />
                    <Input
                        :name="'output_price'"
                        type="number"
                        step="any"
                        min="0"
                        :default-value="String(price.output_price)"
                        :aria-label="`Output price for ${price.model_prefix}`"
                        required
                    />
                    <div class="flex gap-2">
                        <Input
                            :name="'effective_from'"
                            type="date"
                            :default-value="price.effective_from"
                            :aria-label="`Effective date for ${price.model_prefix}`"
                            required
                        />
                        <Button
                            variant="outline"
                            :disabled="processing"
                            :data-test="`save-price-${price.id}`"
                        >
                            Save
                        </Button>
                    </div>
                </Form>
                <Form
                    v-bind="AiPricingController.destroy.form(price.id)"
                    v-slot="{ processing }"
                >
                    <Button
                        variant="outline"
                        :disabled="processing"
                        :data-test="`delete-price-${price.id}`"
                    >
                        Remove
                    </Button>
                </Form>
            </div>
        </div>
    </div>
</template>
