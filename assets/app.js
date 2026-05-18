import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';
import alpine from 'alpinejs';
window.Alpine = alpine;
alpine.start();
import apexcharts from 'apexcharts';
window.ApexCharts = apexcharts;
import codemirror from 'codemirror';
window.CodeMirror = codemirror;

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
