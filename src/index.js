import PlausibleView from "./components/PlausibleView.vue";
import BarCell from "./components/BarCell.vue";
import LicenseDialog from "./components/LicenseDialog.vue";

panel.plugin("medienbaecker/plausibly", {
	components: {
		"k-plausible-view": PlausibleView,
		"k-table-barlabel-cell": BarCell,
		"k-plausibly-license-dialog": LicenseDialog,
	},
});
