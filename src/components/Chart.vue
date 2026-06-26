<template>
	<div class="k-plausible-chart">
		<span ref="probe" class="k-plausible-chart-probe" aria-hidden="true" />
		<div v-show="series.length" ref="chart" class="k-plausible-chart-canvas" />
		<div v-if="loading && !series.length" class="k-plausible-chart-skeleton" />
		<div v-else-if="!series.length" class="k-plausible-chart-empty">
			<k-icon type="chart" />
			{{ $t("medienbaecker.plausibly.empty") }}
		</div>
	</div>
</template>

<script>
import uPlot from "uplot";
import "uplot/dist/uPlot.min.css";
import { formatMetric } from "../helpers.js";

export default {
	props: {
		series: { type: Array, default: () => [] }, // [{ date, value }]
		metric: { type: String, default: "visitors" },
		interval: { type: String, default: "day" },
		loading: { type: Boolean, default: false },
	},
	computed: {
		locale() {
			return this.$panel.translation?.code || undefined;
		},
	},
	watch: {
		series() {
			this.render();
		},
		metric() {
			this.render();
		},
	},
	mounted() {
		this.render();
		this.ro = new ResizeObserver(() => this.resize());
		this.ro.observe(this.$el);

		this.mql = window.matchMedia("(prefers-color-scheme: dark)");
		this.mql.addEventListener("change", this.render);
		const panel = this.$el.closest(".k-panel") || document.documentElement;
		this.mo = new MutationObserver(() => this.render());
		this.mo.observe(panel, { attributes: true, attributeFilter: ["data-theme"] });
	},
	beforeDestroy() {
		this.destroy();
		this.ro?.disconnect();
		this.mo?.disconnect();
		this.mql?.removeEventListener("change", this.render);
	},
	methods: {
		destroy() {
			if (this.chart) {
				this.chart.destroy();
				this.chart = null;
			}
		},
		resize() {
			if (this.chart) {
				this.chart.setSize({ width: this.width(), height: 240 });
			}
		},
		width() {
			return this.$refs.chart?.clientWidth || this.$el.clientWidth || 600;
		},
		colors() {
			const probe = this.$refs.probe;
			const read = (expr) => {
				probe.style.color = expr;
				return getComputedStyle(probe).color;
			};
			return {
				line: read("var(--plausible-accent)"),
				grid: read("var(--color-border)"),
				axis: read("var(--color-text-dimmed)"),
			};
		},
		render() {
			this.destroy();
			if (!this.series.length || !this.$refs.chart) return;

			const xs = this.series.map((p) => {
				const s = String(p.date);
				const iso = s.includes(" ") ? s.replace(" ", "T") : s + "T00:00:00";
				return Date.parse(iso) / 1000;
			});
			const ys = this.series.map((p) => p.value);
			const c = this.colors();
			const metric = this.metric;
			const interval = this.interval;

			let xSplits = [];
			let ySplits = [];

			const DAY = 86400;
			const HOUR = 3600;
			const xIncrs =
				interval === "hour"
					? [HOUR, 2 * HOUR, 3 * HOUR, 6 * HOUR, 12 * HOUR, DAY]
					: interval === "month"
						? [30 * DAY, 60 * DAY, 90 * DAY, 180 * DAY, 365 * DAY]
						: [DAY, 2 * DAY, 7 * DAY, 14 * DAY, 30 * DAY, 90 * DAY];

			// Hour labels ("12:00 AM") are wide; a larger min-gap makes uPlot thin
			// them out instead of overlapping every hour on wide screens.
			const xSpace = interval === "hour" ? 70 : 50;

			const opts = {
				width: this.width(),
				height: 240,
				padding: [0, 0, 0, 0],
				cursor: { y: false, drag: { x: false, y: false }, points: { size: 7 } },
				legend: { show: false },
				scales: {
					x: { time: true },
					y: { range: (u, min, max) => [0, uPlot.rangeNum(min, max, 0.1, true)[1]] },
				},
				axes: [
					{
						stroke: c.axis,
						size: 20,
						space: xSpace,
						grid: { show: false },
						ticks: { show: false },
						incrs: xIncrs,
						values: (u, splits) => {
							xSplits = splits;
							return splits.map(() => "");
						},
					},
					{
						stroke: c.axis,
						size: 0,
						space: 40,
						grid: { stroke: c.grid, width: 1 },
						ticks: { show: false },
						values: (u, splits) => {
							ySplits = splits;
							return splits.map(() => "");
						},
					},
				],
				series: [
					{},
					{
						stroke: c.line,
						width: 2,
						paths: uPlot.paths.spline(),
						points: { show: false },
						fill: (u) => {
							const ctx = u.ctx;
							const { top, height } = u.bbox;
							const grad = ctx.createLinearGradient(0, top, 0, top + height);
							grad.addColorStop(0, this.rgba(c.line, 0.25));
							grad.addColorStop(1, this.rgba(c.line, 0));
							return grad;
						},
						value: (u, v) => formatMetric(metric, v),
					},
				],
				plugins: [
					this.axisLabelsPlugin({
						getX: () => xSplits,
						getY: () => ySplits,
						metric,
						interval,
						color: c.axis,
					}),
					this.tooltipPlugin(),
					this.clickPlugin(),
				],
			};

			this.chart = new uPlot(opts, [xs, ys], this.$refs.chart);
		},
		rgba(rgb, alpha) {
			const m = rgb.match(/\d+/g);
			return m ? `rgba(${m[0]}, ${m[1]}, ${m[2]}, ${alpha})` : rgb;
		},
		formatAxisDate(seconds, interval) {
			const d = new Date(seconds * 1000);
			if (interval === "hour") {
				return d.toLocaleTimeString(this.locale, { hour: "2-digit", minute: "2-digit" });
			}
			if (interval === "month") {
				return d.toLocaleDateString(this.locale, { month: "short" });
			}
			return d.toLocaleDateString(this.locale, { day: "numeric", month: "short" });
		},
		formatTooltipDate(seconds, interval) {
			const d = new Date(seconds * 1000);
			if (interval === "hour") {
				return d.toLocaleString(this.locale, {
					weekday: "short",
					hour: "2-digit",
					minute: "2-digit",
				});
			}
			if (interval === "month") {
				return d.toLocaleDateString(this.locale, { month: "long", year: "numeric" });
			}
			return d.toLocaleDateString(this.locale, {
				weekday: "short",
				day: "numeric",
				month: "short",
				year: "numeric",
			});
		},
		axisLabelsPlugin({ getX, getY, metric, interval, color }) {
			const self = this;
			return {
				hooks: {
					draw: (u) => {
						const ctx = u.ctx;
						const dpr = ctx.canvas.width / u.width;
						const af = u.axes[1].font; // reuse uPlot's resolved axis font
						const left = u.bbox.left;
						const right = u.bbox.left + u.bbox.width;
						ctx.save();
						ctx.font = Array.isArray(af) ? af[0] : af;
						ctx.fillStyle = color;

						ctx.textAlign = "left";
						ctx.textBaseline = "top";
						for (const v of getY()) {
							if (v === 0) continue;
							const y = Math.round(u.valToPos(v, "y", true)) + 3 * dpr;
							ctx.fillText(formatMetric(metric, v), left, y);
						}

						ctx.textAlign = "center";
						ctx.textBaseline = "top";
						const xy = u.bbox.top + u.bbox.height + 5 * dpr;
						let prevText = null;
						for (const t of getX()) {
							const text = self.formatAxisDate(t, interval);
							if (text === prevText) continue;
							const half = ctx.measureText(text).width / 2;
							const cx = Math.round(u.valToPos(t, "x", true));
							if (cx - half < left || cx + half > right) continue;
							prevText = text;
							ctx.fillText(text, cx, xy);
						}

						ctx.restore();
					},
				},
			};
		},
		clickPlugin() {
			const self = this;
			return {
				hooks: {
					init: (u) => {
						if (self.interval !== "hour") u.over.style.cursor = "pointer";
						u.over.addEventListener("click", () => {
							if (self.interval === "hour") return;
							const idx = u.cursor.idx;
							const date = idx == null ? null : self.series[idx]?.date;
							if (date) self.$emit("select", String(date).slice(0, 10));
						});
					},
				},
			};
		},
		tooltipPlugin() {
			const self = this;
			let tip, valueEl, dateEl;
			return {
				hooks: {
					init: (u) => {
						tip = document.createElement("div");
						tip.className = "k-plausible-chart-tooltip";
						valueEl = document.createElement("span");
						valueEl.className = "k-plausible-chart-tooltip-value";
						dateEl = document.createElement("span");
						dateEl.className = "k-plausible-chart-tooltip-date";
						tip.append(valueEl, dateEl);
						u.over.appendChild(tip);
						u.over.addEventListener("mouseleave", () => tip.removeAttribute("data-show"));
					},
					setCursor: (u) => {
						const idx = u.cursor.idx;
						const y = idx == null ? null : u.data[1][idx];
						if (y == null) {
							tip.removeAttribute("data-show");
							return;
						}
						valueEl.textContent = formatMetric(self.metric, y);
						dateEl.textContent = self.formatTooltipDate(u.data[0][idx], self.interval);
						tip.setAttribute("data-show", "true");
					},
				},
			};
		},
	},
};
</script>

<style>
.k-plausible-chart {
	position: relative;
	min-height: 240px;
}

.k-plausible-chart-probe {
	position: absolute;
	width: 0;
	height: 0;
	visibility: hidden;
}

.k-plausible-chart-empty {
	position: absolute;
	inset: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: var(--spacing-2);
	color: var(--color-text-dimmed);
	font-size: var(--text-sm);
}

.k-plausible-chart .u-cursor-pt {
	anchor-name: --k-plausible-point;
}

.k-plausible-chart .u-cursor-x {
	border-right-color: color-mix(in srgb, var(--color-text-dimmed), transparent 75%);
}

.k-plausible-chart-tooltip {
	position: absolute;
	position-anchor: --k-plausible-point;
	position-area: block-start;
	position-try-fallbacks: flip-block;
	margin-block: var(--spacing-2);
	z-index: 2;
	pointer-events: none;
	display: none;
	flex-direction: column;
	gap: 2px;
	padding: var(--spacing-1) var(--spacing-2);
	background: var(--color-black);
	color: var(--color-white);
	border-radius: var(--rounded);
	box-shadow: var(--shadow-lg);
	white-space: nowrap;
	font-size: var(--text-xs);
	line-height: 1.35;

	&[data-show] {
		display: flex;
	}
}

.k-plausible-chart-tooltip-value {
	font-weight: var(--font-bold);
}

.k-plausible-chart-tooltip-date {
	color: var(--color-gray-400);
}
</style>
