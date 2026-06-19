<template>
	<dl class="k-plausible-stats" :data-wrapped="wrapped" :data-active-first="activeFirst"
		:data-active-last="activeLast">
		<k-stat v-for="kpi in KPIS" :key="kpi.key" class="k-plausible-stat" :aria-current="kpi.key === selected || null"
			:label="$t('medienbaecker.plausibly.metric.' + kpi.key)" :value="value(kpi)" :info="delta(kpi)"
			:theme="theme(kpi)" :click="() => $emit('select', kpi.key)" />
	</dl>
</template>

<script>
import { KPIS, formatMetric } from "../helpers.js";

export default {
	props: {
		data: { type: Object, default: () => ({}) },
		selected: { type: String, default: null },
	},
	data() {
		return { KPIS, wrapped: false };
	},
	computed: {
		activeFirst() {
			return this.selected === KPIS[0].key;
		},
		activeLast() {
			return this.selected === KPIS[KPIS.length - 1].key;
		},
	},
	mounted() {
		this.checkWrap();
		this.ro = new ResizeObserver(() => this.checkWrap());
		this.ro.observe(this.$el);
	},
	beforeDestroy() {
		this.ro?.disconnect();
	},
	methods: {
		checkWrap() {
			const items = Array.from(this.$el.children);
			const top = items[0]?.offsetTop;
			this.wrapped = items.some((el) => el.offsetTop !== top);
		},
		stat(kpi) {
			return this.data[kpi.key];
		},
		value(kpi) {
			const stat = this.stat(kpi);
			return stat ? formatMetric(kpi.key, stat.value) : "—";
		},
		delta(kpi) {
			const change = this.stat(kpi)?.change ?? null;
			if (change === null) return " ";
			if (change === 0) return "0%";
			return (change > 0 ? "↑ " : "↓ ") + Math.abs(change) + "%";
		},
		theme(kpi) {
			const change = this.stat(kpi)?.change ?? null;
			if (!change) return null;
			return (kpi.up ? change > 0 : change < 0) ? "positive" : "negative";
		},
	},
};
</script>

<style>
.k-plausible-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr));
	gap: var(--spacing-2px);
}

.k-plausible-stat {
	--stat-color-hover-back: linear-gradient(to bottom, var(--plausible-fill-soft), transparent), var(--stat-color-back);

	&[aria-current="true"] {
		background: var(--stat-color-hover-back);

		.k-stat-value {
			color: var(--plausible-accent);
		}

		.k-plausible-stats:not([data-wrapped="true"]) & {
			transform: translateY(3px);
			border-end-start-radius: 0;
			border-end-end-radius: 0;
			box-shadow: none;
			border: 1px solid var(--table-color-border);
			border-block-end: 0;
		}
	}
}
</style>
