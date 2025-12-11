/**
 * Optimized Image Slideshow Component
 *
 * This component reads shared slideshow data from a single JSON script tag
 * instead of duplicating data for each thumbnail, reducing memory usage
 * from O(n²) to O(n) for tasks with many attachments.
 */
(function() {

// Global cache for parsed slideshow data (shared across all component instances)
var slideshowDataCache = {};

KB.component('image-slideshow-optimized', function (containerElement, options) {
    var currentImage;
    var slideshowId = containerElement.getAttribute('data-slideshow-id');

    /**
     * Get shared options from the JSON script tag (parsed once, cached globally)
     *
     * @returns {Object|null} The shared slideshow configuration
     */
    function getSharedOptions() {
        // Return from cache if already parsed
        if (slideshowDataCache[slideshowId]) {
            return slideshowDataCache[slideshowId];
        }

        var scriptElement = document.getElementById(slideshowId);

        if (scriptElement) {
            try {
                slideshowDataCache[slideshowId] = JSON.parse(scriptElement.textContent);
                return slideshowDataCache[slideshowId];
            } catch (e) {
                console.error('Failed to parse slideshow data:', e);
                return null;
            }
        }

        return null;
    }

    /**
     * Handle keyboard navigation
     *
     * @param {KeyboardEvent} e
     */
    function onKeyDown(e) {
        switch (KB.utils.getKey(e)) {
            case 'Escape':
                destroySlide();
                break;
            case 'ArrowRight':
                renderNextSlide();
                break;
            case 'ArrowLeft':
                renderPreviousSlide();
                break;
        }
    }

    /**
     * Handle clicks on the overlay
     *
     * @param {Element} element
     */
    function onOverlayClick(element) {
        if (element.matches('.slideshow-next-icon')) {
            renderNextSlide();
        } else if (element.matches('.slideshow-previous-icon')) {
            renderPreviousSlide();
        } else if (element.matches('.slideshow-download-icon')) {
            window.location.href = element.href;
        } else {
            destroySlide();
        }
    }

    /**
     * Handle thumbnail click to open slideshow
     *
     * @param {Element} element
     */
    function onClick(element) {
        var imageId = KB.dom(element).data('imageId');
        var image = getImage(imageId);

        if (image) {
            currentImage = image;
            renderSlide();
        }
    }

    /**
     * Navigate to next slide
     */
    function renderNextSlide() {
        var opts = getSharedOptions();
        if (!opts) return;

        destroySlide();

        var currentId = parseInt(currentImage.id, 10);
        for (var i = 0; i < opts.images.length; i++) {
            if (parseInt(opts.images[i].id, 10) === currentId) {
                var index = i + 1;

                if (index >= opts.images.length) {
                    index = 0;
                }

                currentImage = opts.images[index];
                break;
            }
        }

        renderSlide();
    }

    /**
     * Navigate to previous slide
     */
    function renderPreviousSlide() {
        var opts = getSharedOptions();
        if (!opts) return;

        destroySlide();

        var currentId = parseInt(currentImage.id, 10);
        for (var i = 0; i < opts.images.length; i++) {
            if (parseInt(opts.images[i].id, 10) === currentId) {
                var index = i - 1;

                if (index < 0) {
                    index = opts.images.length - 1;
                }

                currentImage = opts.images[index];
                break;
            }
        }

        renderSlide();
    }

    /**
     * Render the full-screen slide overlay
     */
    function renderSlide() {
        var closeElement = KB.dom('div')
            .attr('class', 'fa fa-window-close slideshow-icon slideshow-close-icon')
            .build();

        var downloadElement = KB.dom('a')
            .attr('class', 'fa fa-download slideshow-icon slideshow-download-icon')
            .attr('href', getUrl(currentImage, 'download'))
            .build();

        var previousElement = KB.dom('div')
            .attr('class', 'fa fa-chevron-circle-left slideshow-icon slideshow-previous-icon')
            .build();

        var nextElement = KB.dom('div')
            .attr('class', 'fa fa-chevron-circle-right slideshow-icon slideshow-next-icon')
            .build();

        var imageElement = KB.dom('img')
            .attr('src', getUrl(currentImage, 'image'))
            .attr('alt', currentImage.name)
            .attr('title', currentImage.name)
            .style('maxHeight', (window.innerHeight - 50) + 'px')
            .build();

        var captionElement = KB.dom('figcaption')
            .text(currentImage.name)
            .build();

        var figureElement = KB.dom('figure')
            .add(imageElement)
            .add(captionElement)
            .build();

        var overlayElement = KB.dom('div')
            .addClass('image-slideshow-overlay')
            .add(closeElement)
            .add(downloadElement)
            .add(previousElement)
            .add(nextElement)
            .add(figureElement)
            .click(onOverlayClick)
            .build();

        document.body.appendChild(overlayElement);
        document.addEventListener('keydown', onKeyDown, false);
    }

    /**
     * Remove the slideshow overlay
     */
    function destroySlide() {
        var overlayElement = KB.find('.image-slideshow-overlay');

        if (overlayElement !== null) {
            document.removeEventListener('keydown', onKeyDown, false);
            overlayElement.remove();
        }
    }

    /**
     * Find an image by ID from shared options
     *
     * @param {number|string} imageId
     * @returns {Object|null}
     */
    function getImage(imageId) {
        var opts = getSharedOptions();
        if (!opts) return null;

        // Convert to integer for comparison (handles both string and number input)
        var targetId = parseInt(imageId, 10);

        for (var i = 0; i < opts.images.length; i++) {
            if (parseInt(opts.images[i].id, 10) === targetId) {
                return opts.images[i];
            }
        }

        return null;
    }

    /**
     * Build URL for image operations
     *
     * @param {Object} image
     * @param {string} type
     * @returns {string}
     */
    function getUrl(image, type) {
        var opts = getSharedOptions();
        if (!opts) return '';

        var regexFileID = new RegExp(opts.regex_file_id, 'g');
        var regexFileEtag = new RegExp(opts.regex_etag, 'g');
        return opts.url[type].replace(regexFileID, image.id).replace(regexFileEtag, image.etag);
    }

    /**
     * Build the thumbnail element
     *
     * @param {Object} image
     * @returns {Element}
     */
    function buildThumbnailElement(image) {
        return KB.dom('img')
            .attr('src', getUrl(image, 'thumbnail'))
            .attr('alt', image.name)
            .attr('title', image.name)
            .data('imageId', image.id)
            .click(onClick)
            .build();
    }

    /**
     * Render the component
     */
    this.render = function () {
        var imageId = parseInt(containerElement.getAttribute('data-image-id'), 10);
        currentImage = getImage(imageId);

        if (currentImage) {
            containerElement.appendChild(buildThumbnailElement(currentImage));
        }
    };
});

// Render any image-slideshow-optimized components that exist
// (needed because this file loads after KB.render() has already run)
var elements = document.querySelectorAll('.js-image-slideshow-optimized:not(.js-image-slideshow-optimized-rendered)');
for (var i = 0; i < elements.length; i++) {
    var component = KB.getComponent('image-slideshow-optimized', elements[i], {});
    component.render();
    elements[i].className = elements[i].className + '-rendered';
}
})();

