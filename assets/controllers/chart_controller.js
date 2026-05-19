import { Controller } from '@hotwired/stimulus';
import ApexCharts from 'apexcharts';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        options: Object,
        series: Array,
        type: String,
    };

    connect() {
        this.chart = new ApexCharts(this.element, this.buildOptions());
        this.chart.render();
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }

    optionsValueChanged() {
        if (this.chart) {
            this.chart.updateOptions(this.buildOptions());
        }
    }

    seriesValueChanged() {
        if (this.chart && this.hasSeriesValue) {
            this.chart.updateSeries(this.seriesValue);
        }
    }

    buildOptions() {
        const options = this.hasOptionsValue ? { ...this.optionsValue } : {};

        if (this.hasTypeValue) {
            options.chart = { ...(options.chart || {}), type: this.typeValue };
        }

        if (this.hasSeriesValue) {
            options.series = this.seriesValue;
        }

        return options;
    }
}
