/**
 * Parallel File Upload Component
 * 
 * Uploads multiple files simultaneously for faster batch uploads.
 * Supports up to 3 concurrent uploads with automatic queuing.
 * 
 * @param {object} options - Configuration options
 * @param {string} options.url - Upload endpoint URL
 * @param {string} options.csrf - CSRF token
 * @param {number} options.maxSize - Maximum file size in bytes
 * @param {number} options.maxParallel - Max concurrent uploads (default: 3)
 */
KB.component('file-upload-parallel', function (containerElement, options) {
    var inputFileElement = null;
    var dropzoneElement = null;
    var files = [];
    var maxParallel = options.maxParallel || 3;
    
    var uploadStats = {
        total: 0,
        completed: 0,
        failed: 0,
        active: 0,
        queueIndex: 0
    };

    function onProgress(index, e) {
        if (e.lengthComputable) {
            var progress = e.loaded / e.total;
            var percentage = Math.floor(progress * 100);

            KB.find('#file-progress-' + index).attr('value', progress);
            KB.find('#file-percentage-' + index).replaceText('(' + percentage + '%)');
        }
    }

    function onFileComplete(index) {
        uploadStats.completed++;
        uploadStats.active--;
        
        // Mark as complete (green checkmark)
        var successElement = KB.dom('span')
            .addClass('file-success')
            .html(' <i class="fa fa-check" style="color: green;"></i>')
            .build();
        KB.find('#file-item-' + index).add(successElement);
        
        // Start next upload from queue
        startNextUpload();
        
        // Check if all files are done
        checkAllComplete();
    }

    function onFileError(index, message) {
        uploadStats.failed++;
        uploadStats.active--;
        
        var errorMessage = message || options.labelUploadError;
        var errorElement = KB.dom('div')
            .addClass('file-error')
            .text(errorMessage)
            .build();
        KB.find('#file-item-' + index).add(errorElement);
        
        // Start next upload from queue
        startNextUpload();
        
        // Check if all files are done
        checkAllComplete();
    }

    function onRequestTooLarge(index) {
        onFileError(index, options.labelOversize);
    }

    function startNextUpload() {
        if (uploadStats.queueIndex >= uploadStats.total) {
            return; // No more files to upload
        }
        
        if (uploadStats.active >= maxParallel) {
            return; // Already at max parallel uploads
        }
        
        var index = uploadStats.queueIndex++;
        uploadStats.active++;
        
        KB.http.uploadFile(
            options.url,
            files[index],
            options.csrf,
            function(e) { onProgress(index, e); },
            function() { onFileComplete(index); },
            function() { onFileError(index); },
            function(response) { onFileError(index, response.message); },
            function() { onRequestTooLarge(index); }
        );
    }

    function checkAllComplete() {
        if (uploadStats.completed + uploadStats.failed === uploadStats.total) {
            KB.trigger('modal.stop');
            
            if (uploadStats.failed === 0) {
                // All succeeded
                showSuccessMessage();
            } else if (uploadStats.completed === 0) {
                // All failed
                showAllFailedMessage();
            } else {
                // Partial success
                showPartialSuccessMessage();
            }
        }
    }

    function showSuccessMessage() {
        KB.trigger('modal.hide');
        
        var alertElement = KB.dom('div')
            .addClass('alert')
            .addClass('alert-success')
            .text(options.labelSuccess)
            .build();

        var buttonElement = KB.dom('button')
            .attr('type', 'button')
            .addClass('btn')
            .addClass('btn-blue')
            .click(function() { window.location.reload(); })
            .text(options.labelCloseSuccess)
            .build();

        KB.dom(dropzoneElement).replace(KB.dom('div').add(alertElement).add(buttonElement).build());
    }

    function showPartialSuccessMessage() {
        var message = uploadStats.completed + ' files uploaded successfully, ' + uploadStats.failed + ' failed.';
        
        var alertElement = KB.dom('div')
            .addClass('alert')
            .addClass('alert-info')
            .text(message)
            .build();

        var buttonElement = KB.dom('button')
            .attr('type', 'button')
            .addClass('btn')
            .addClass('btn-blue')
            .click(function() { window.location.reload(); })
            .text(options.labelCloseSuccess)
            .build();

        KB.dom('#file-list').add(alertElement).add(buttonElement);
    }

    function showAllFailedMessage() {
        var alertElement = KB.dom('div')
            .addClass('alert')
            .addClass('alert-error')
            .text('All uploads failed. Please try again.')
            .build();

        KB.dom('#file-list').add(alertElement);
    }

    function onSubmit() {
        uploadStats.total = files.length;
        uploadStats.completed = 0;
        uploadStats.failed = 0;
        uploadStats.active = 0;
        uploadStats.queueIndex = 0;
        
        // Start initial batch of uploads
        for (var i = 0; i < Math.min(maxParallel, files.length); i++) {
            startNextUpload();
        }
    }

    function onFileChange() {
        for (var i = 0; i < inputFileElement.files.length; i++) {
            files.push(inputFileElement.files[i]);
        }
        showFiles();
    }

    function onClickFileBrowser() {
        files = [];
        inputFileElement.click();
    }

    function onDragOver(e) {
        e.stopPropagation();
        e.preventDefault();
    }

    function onDrop(e) {
        e.stopPropagation();
        e.preventDefault();

        for (var i = 0; i < e.dataTransfer.files.length; i++) {
            files.push(e.dataTransfer.files[i]);
        }

        showFiles();
    }

    function showFiles() {
        if (files.length > 0) {
            KB.trigger('modal.enable');

            KB.dom(dropzoneElement)
                .empty()
                .add(buildFileListElement());
        } else {
            KB.trigger('modal.disable');

            KB.dom(dropzoneElement)
                .empty()
                .add(buildInnerDropzoneElement());
        }
    }

    function buildFileInputElement() {
        return KB.dom('input')
            .attr('id', 'file-input-element')
            .attr('type', 'file')
            .attr('name', 'files[]')
            .attr('multiple', true)
            .on('change', onFileChange)
            .hide()
            .build();
    }

    function buildInnerDropzoneElement() {
        var dropzoneLinkElement = KB.dom('a')
            .attr('href', '#')
            .text(options.labelChooseFiles)
            .click(onClickFileBrowser)
            .build();

        return KB.dom('div')
            .attr('id', 'file-dropzone-inner')
            .text(options.labelDropzone + ' ' + options.labelOr + ' ')
            .add(dropzoneLinkElement)
            .build();
    }

    function buildDropzoneElement() {
        var dropzoneElement = KB.dom('div')
            .attr('id', 'file-dropzone')
            .add(buildInnerDropzoneElement())
            .build();

        dropzoneElement.ondragover = onDragOver;
        dropzoneElement.ondrop = onDrop;

        return dropzoneElement;
    }

    function buildFileListItem(index) {
        var isOversize = false;
        var progressElement = KB.dom('progress')
            .attr('id', 'file-progress-' + index)
            .attr('value', 0)
            .build();

        var percentageElement = KB.dom('span')
            .attr('id', 'file-percentage-' + index)
            .text('(0%)')
            .build();

        var deleteElement = KB.dom('span')
            .attr('id', 'file-delete-' + index)
            .html('<a href="#"><i class="fa fa-trash fa-fw"></i></a>')
            .on('click', function () {
                files.splice(index, 1);
                KB.find('#file-item-' + index).remove();
                showFiles();
            })
            .build();

        var itemElement = KB.dom('li')
            .attr('id', 'file-item-' + index)
            .add(deleteElement)
            .add(progressElement)
            .text(' ' + files[index].name + ' ')
            .add(percentageElement);

        if (options.maxSize > 0 && files[index].size > options.maxSize) {
            itemElement.add(KB.dom('div').addClass('file-error').text(options.labelOversize).build());
            isOversize = true;
        }

        if (isOversize) {
            KB.trigger('modal.disable');
        }

        return itemElement.build();
    }

    function buildFileListElement() {
        var fileListElement = KB.dom('ul')
            .attr('id', 'file-list')
            .build();

        for (var i = 0; i < files.length; i++) {
            fileListElement.appendChild(buildFileListItem(i));
        }

        // Add info about parallel uploads
        var infoElement = KB.dom('p')
            .addClass('form-help')
            .text('Uploading ' + maxParallel + ' files simultaneously...')
            .build();
        
        var container = KB.dom('div')
            .add(infoElement)
            .add(fileListElement)
            .build();

        return container;
    }

    this.render = function () {
        KB.on('modal.submit', onSubmit);
        KB.on('modal.close', function () {
           KB.removeListener('modal.submit', onSubmit);
        });

        inputFileElement = buildFileInputElement();
        dropzoneElement = buildDropzoneElement();
        containerElement.appendChild(inputFileElement);
        containerElement.appendChild(dropzoneElement);
    };
});
