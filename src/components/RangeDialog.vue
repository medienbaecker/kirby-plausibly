<template>
	<k-dialog class="k-plausibly-range-dialog" size="large" :cancel-button="true"
		:submit-button="{ text: $t('medienbaecker.plausibly.apply'), theme: 'positive' }" :visible="true"
		@cancel="$emit('cancel')" @submit="submit">
		<div class="k-plausibly-range-fields">
			<div>
				<k-label>{{ $t("medienbaecker.plausibly.range.from") }}</k-label>
				<k-calendar :value="from" :max="to || today" @input="from = ymd($event)" />
			</div>
			<div>
				<k-label>{{ $t("medienbaecker.plausibly.range.to") }}</k-label>
				<k-calendar :value="to" :min="from" :max="today" @input="to = ymd($event)" />
			</div>
		</div>
	</k-dialog>
</template>

<script>
export default {
	props: {
		initialFrom: { type: String, default: null },
		initialTo: { type: String, default: null },
	},
	data() {
		return {
			from: this.initialFrom,
			to: this.initialTo,
		};
	},
	computed: {
		today() {
			const now = new Date();
			const m = String(now.getMonth() + 1).padStart(2, "0");
			const d = String(now.getDate()).padStart(2, "0");
			return `${now.getFullYear()}-${m}-${d}`;
		},
	},
	methods: {
		ymd(value) {
			return value ? String(value).slice(0, 10) : null;
		},
		submit() {
			if (!this.from || !this.to || this.from > this.to) return;
			this.$emit("submit", { from: this.from, to: this.to });
		},
	},
};
</script>

<style>
.k-plausibly-range-fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr));
	gap: var(--spacing-4);
}

.k-plausibly-range-fields .k-label {
	margin-block-end: var(--spacing-2);
}

.k-plausibly-range-fields .k-calendar-input {
	background: var(--item-color-back);
	border-radius: var(--rounded);
}
</style>
