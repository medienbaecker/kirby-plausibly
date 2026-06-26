<template>
	<k-section :label="title">
		<template v-if="tabs.length > 1" #options>
			<div class="k-plausible-tab-select">
				<k-button :dropdown="true" variant="filled" size="xs" :text="tab.label" @click="$refs.tabs.toggle()" />
				<k-dropdown-content ref="tabs" align-x="end">
					<k-dropdown-item v-for="t in tabs" :key="t.name" :current="t.name === active"
						@click="select(t.name)">
						{{ t.label }}
					</k-dropdown-item>
				</k-dropdown-content>
			</div>
		</template>

		<k-empty v-if="loading && !rows.length" icon="loader" />
		<k-empty v-else-if="!rows.length" icon="chart">
			{{ $t("medienbaecker.plausibly.empty") }}
		</k-empty>
		<k-table v-else :class="['k-plausible-bars', { 'k-plausible-flush': loadedTab.columns.length === 1 }]"
			:columns="table.columns" :rows="visibleRows" :index="false" @cell="onCell" />
	</k-section>
</template>

<script>
import { toTable, fetchData } from "../helpers.js";

const COLLAPSED = 9;
const MAX = 100;

export default {
	props: {
		title: { type: String, required: true },
		tabs: { type: Array, required: true },
		period: { type: String, default: "28d" },
		date: { type: String, default: null },
		faviconBase: { type: String, default: "" },
		defaultTab: { type: String, default: null },
	},
	data() {
		return {
			active: this.defaultTab || this.tabs[0].name,
			loaded: this.defaultTab || this.tabs[0].name,
			rows: [],
			loading: false,
			expanded: false,
		};
	},
	computed: {
		tab() {
			return this.tabs.find((t) => t.name === this.active) || this.tabs[0];
		},
		loadedTab() {
			return this.tabs.find((t) => t.name === this.loaded) || this.tabs[0];
		},
		table() {
			// Built from the loaded tab, never the selected one, so columns and
			// label formatting always match the rows currently in hand.
			return toTable(this.rows, {
				columnDefs: this.loadedTab.columns,
				labelHeader: this.loadedTab.labelHeader,
				iconType: this.loadedTab.icon,
				faviconBase: this.faviconBase,
				notSet: this.$t("medienbaecker.plausibly.notSet"),
				locale: this.locale,
			});
		},
		visibleRows() {
			const all = this.table.rows;
			const out = this.expanded ? [...all] : all.slice(0, COLLAPSED);
			if (all.length > COLLAPSED) {
				out.push({
					_more: true,
					_expanded: this.expanded,
					label: this.$t(
						"medienbaecker.plausibly." + (this.expanded ? "showLess" : "showMore")
					),
				});
			}
			return out;
		},
		locale() {
			return this.$panel.translation?.code || "en";
		},
		// One key for both so changing period+date together reloads once, not twice.
		window() {
			return `${this.period}|${this.date}`;
		},
	},
	watch: {
		window() {
			this.expanded = false;
			this.load();
		},
	},
	mounted() {
		this.load();
	},
	methods: {
		async load() {
			const token = (this.loadToken = Symbol());
			this.loading = true;
			try {
				const rows = await fetchData(this.$api, "plausible/breakdown", {
					dimension: this.tab.dimension,
					metrics: this.tab.metrics.join(","),
					period: this.period,
					...(this.date ? { date: this.date } : {}),
					limit: MAX,
				});
				if (token !== this.loadToken) return;
				// Swap rows and the displayed tab together so the table never
				// renders new columns against the previous tab's data.
				this.rows = rows;
				this.loaded = this.active;
			} catch (e) {
				if (token !== this.loadToken) return;
				this.$panel.notification.error(e.message || this.$t("medienbaecker.plausibly.error"));
				this.rows = [];
				this.loaded = this.active;
			} finally {
				if (token === this.loadToken) this.loading = false;
			}
		},
		select(name) {
			if (name === this.active) return;
			this.active = name;
			this.expanded = false;
			this.load();
		},
		onCell({ row }) {
			if (row && row._more) {
				this.expanded = !this.expanded;
			}
		},
	},
};
</script>

<style>
.k-plausible-tab-select {
	position: relative;
}
</style>
