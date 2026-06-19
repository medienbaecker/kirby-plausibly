<template>
	<k-panel-inside class="k-plausible-view">
		<k-header>
			{{ site }}

			<template #buttons>
				<span v-if="realtime !== null" class="k-plausible-live" :data-active="realtime > 0">
					<k-icon type="live" class="k-plausible-live-icon" />
					{{ realtimeLabel }}
				</span>

				<div class="k-plausible-period">
					<k-button :dropdown="true" icon="calendar" variant="filled" size="sm" :text="periodLabel"
						@click="$refs.periods.toggle()" />
					<k-dropdown-content ref="periods" align-x="end">
						<k-dropdown-item v-for="p in periodOptions" :key="p" icon="calendar" :current="p === period"
							@click="setPeriod(p)">
							{{ periodName(p) }}
						</k-dropdown-item>
					</k-dropdown-content>
				</div>
			</template>
		</k-header>

		<StatTiles :data="kpis" :selected="metric" @select="setMetric" />

		<section class="k-plausible-chart-card">
			<Chart :series="series" :metric="metric" :interval="interval" :loading="loadingChart" />
		</section>

		<k-grid variant="columns" class="k-plausible-grid">
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.topSources')" :tabs="cards.sources"
					default-tab="sources" :period="period" :favicon-base="faviconBase" />
			</k-column>
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.topPages')" :tabs="cards.pages" :period="period" />
			</k-column>
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.locations')" :tabs="cards.locations"
					:period="period" />
			</k-column>
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.devices')" :tabs="cards.devices"
					:period="period" />
			</k-column>
			<k-column width="1/1">
				<BreakdownCard :title="$t('medienbaecker.plausibly.goals')" :tabs="cards.goals" :period="period" />
			</k-column>
		</k-grid>
	</k-panel-inside>
</template>

<script>
import StatTiles from "./StatTiles.vue";
import Chart from "./Chart.vue";
import BreakdownCard from "./BreakdownCard.vue";
import { PERIODS, intervalFor, fetchData } from "../helpers.js";

export default {
	components: { StatTiles, Chart, BreakdownCard },
	props: {
		site: { type: String, default: "" },
		faviconBase: { type: String, default: "" },
	},
	data() {
		const query = this.$panel.view?.query || {};
		const period = PERIODS.includes(query.period) ? query.period : "28d";
		return {
			period,
			interval: intervalFor(period),
			metric: "visitors",
			kpis: {},
			series: [],
			realtime: null,
			loadingChart: false,
			pollTimer: null,
		};
	},
	computed: {
		periodOptions() {
			return PERIODS;
		},
		periodLabel() {
			return this.periodName(this.period);
		},
		realtimeLabel() {
			const key = this.realtime === 1 ? "currentVisitors" : "currentVisitors.plural";
			return this.$t("medienbaecker.plausibly." + key, { count: this.realtime });
		},
		cards() {
			const t = (key) => this.$t("medienbaecker.plausibly." + key);
			const visitors = { key: "visitors", header: t("column.visitors") };

			return {
				sources: [
					{ name: "sources", label: t("sources"), dimension: "visit:source", icon: "favicon", labelHeader: t("column.source"), metrics: ["visitors"], columns: [visitors] },
					{ name: "channels", label: t("channels"), dimension: "visit:channel", icon: null, labelHeader: t("column.channel"), metrics: ["visitors"], columns: [visitors] },
					{ name: "utm", label: t("utm"), dimension: "visit:utm_campaign", icon: null, labelHeader: t("column.campaign"), metrics: ["visitors"], columns: [visitors] },
				],
				pages: [
					{
						name: "top", label: t("pages"), dimension: "event:page", icon: null, labelHeader: t("column.page"),
						metrics: ["visitors", "pageviews", "bounce_rate"],
						columns: [visitors, { key: "pageviews", header: t("column.pageviews") }, { key: "bounce_rate", header: t("column.bounceRate") }],
					},
					{ name: "entry", label: t("entryPages"), dimension: "visit:entry_page", icon: null, labelHeader: t("column.page"), metrics: ["visitors"], columns: [visitors] },
					{ name: "exit", label: t("exitPages"), dimension: "visit:exit_page", icon: null, labelHeader: t("column.page"), metrics: ["visitors"], columns: [visitors] },
				],
				locations: [
					{ name: "countries", label: t("countries"), dimension: "visit:country", icon: "flag", labelHeader: t("column.country"), metrics: ["visitors"], columns: [visitors] },
					{ name: "regions", label: t("regions"), dimension: "visit:region_name", icon: null, labelHeader: t("column.region"), metrics: ["visitors"], columns: [visitors] },
					{ name: "cities", label: t("cities"), dimension: "visit:city_name", icon: null, labelHeader: t("column.city"), metrics: ["visitors"], columns: [visitors] },
				],
				devices: [
					{ name: "browser", label: t("browser"), dimension: "visit:browser", icon: null, labelHeader: t("column.browser"), metrics: ["visitors"], columns: [visitors] },
					{ name: "os", label: t("os"), dimension: "visit:os", icon: null, labelHeader: t("column.os"), metrics: ["visitors"], columns: [visitors] },
					{ name: "size", label: t("screenSize"), dimension: "visit:device", icon: null, labelHeader: t("column.screenSize"), metrics: ["visitors"], columns: [visitors] },
				],
				goals: [
					{
						name: "goals", label: t("goals"), dimension: "event:goal", icon: null, labelHeader: t("column.goal"),
						metrics: ["visitors", "events", "conversion_rate"],
						columns: [
							{ key: "visitors", header: t("column.uniques") },
							{ key: "events", header: t("column.total") },
							{ key: "conversion_rate", header: t("column.cr") },
						],
					},
				],
			};
		},
	},
	created() {
		this.loadKpis();
		this.loadChart();
		this.loadRealtime();
		this.pollTimer = setInterval(this.loadRealtime, 15000);
	},
	beforeDestroy() {
		clearInterval(this.pollTimer);
	},
	methods: {
		periodName(p) {
			return this.$t("medienbaecker.plausibly.period." + p);
		},
		setPeriod(p) {
			if (p === this.period) return;
			this.period = p;
			this.interval = intervalFor(p);
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		setMetric(metric) {
			this.metric = metric;
			this.loadChart();
		},
		updateUrl() {
			const url = new URL(window.location.href);
			url.searchParams.set("period", this.period);
			window.history.replaceState({}, "", url);
		},
		async loadKpis() {
			const token = (this.kpiToken = Symbol());
			try {
				const kpis = await fetchData(this.$api, "plausible/aggregate", { period: this.period });
				if (token !== this.kpiToken) return; // superseded by a newer request
				this.kpis = kpis;
			} catch (e) {
				this.$panel.notification.error(e.message || this.$t("medienbaecker.plausibly.error"));
			}
		},
		async loadChart() {
			const token = (this.chartToken = Symbol());
			this.loadingChart = true;
			this.series = []; // show the skeleton right away while loading
			try {
				const series = await fetchData(this.$api, "plausible/timeseries", {
					period: this.period,
					metric: this.metric,
					interval: this.interval,
				});
				if (token !== this.chartToken) return; // superseded by a newer request
				this.series = series;
			} catch (e) {
				if (token !== this.chartToken) return;
				this.$panel.notification.error(e.message || this.$t("medienbaecker.plausibly.error"));
				this.series = [];
			} finally {
				if (token === this.chartToken) this.loadingChart = false;
			}
		},
		async loadRealtime() {
			try {
				// silent: background poll, no global loading cursor/spinner
				this.realtime = await fetchData(this.$api, "plausible/realtime", {}, { silent: true });
			} catch (e) {
				/* keep last known value */
			}
		},
	},
};
</script>

<style>
.k-plausible-view {
	--plausible-accent-light: var(--color-blue-700);
	--plausible-accent-dark: var(--color-blue-300);
	--plausible-accent: light-dark(var(--plausible-accent-light), var(--plausible-accent-dark));

	--plausible-fill-hue: var(--color-blue-400);
	--plausible-fill: light-dark(color-mix(in srgb, var(--plausible-fill-hue), transparent 75%),
			color-mix(in srgb, var(--plausible-fill-hue), transparent 85%));
	--plausible-fill-soft: light-dark(color-mix(in srgb, var(--plausible-fill-hue), transparent 90%),
			color-mix(in srgb, var(--plausible-fill-hue), transparent 95%));
}

.k-plausible-view .k-header-buttons {
	align-items: center;
}

.k-plausible-live {
	display: inline-flex;
	align-items: center;
	gap: var(--spacing-1);
	height: var(--height-sm);
	padding-inline: var(--spacing-2);
	background: var(--item-color-back);
	border-radius: var(--rounded);
	font-size: var(--text-sm);
	white-space: nowrap;
	color: var(--color-text-dimmed);
}

.k-plausible-live-icon {
	color: var(--color-text-dimmed);
}

.k-plausible-live[data-active="true"] {
	color: var(--color-text);

	.k-plausible-live-icon {
		color: var(--plausible-accent);
	}
}

.k-plausible-period {
	position: relative;
}

.k-plausible-chart-card {
	margin-block-start: var(--spacing-2px);
	padding: var(--spacing-6);
	background: var(--table-color-back);
	border: 1px solid var(--table-color-border);
	border-radius: var(--rounded);
}

.k-plausible-stats:not([data-wrapped="true"])[data-active-first="true"]+.k-plausible-chart-card {
	border-start-start-radius: 0;
}

.k-plausible-stats:not([data-wrapped="true"])[data-active-last="true"]+.k-plausible-chart-card {
	border-start-end-radius: 0;
}

.k-plausible-grid {
	--gap: var(--spacing-8);
	column-gap: var(--gap);
	margin-block-start: var(--gap);
}
</style>
