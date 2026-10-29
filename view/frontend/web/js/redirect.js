define([], function () {
    'use strict';
    return function (config, element) {
        if (config.auto === true) { element.requestSubmit(); }
    };
});
