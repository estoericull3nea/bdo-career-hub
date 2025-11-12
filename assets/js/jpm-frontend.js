jQuery(document).ready(function ($) {
  // Toast notification function
  function showToast(message, type) {
    type = type || "success"; // success, error, warning, info
    var toast = $(
      '<div class="jpm-toast jpm-toast-' +
        type +
        '">' +
        '<div class="jpm-toast-content">' +
        '<span class="jpm-toast-icon"></span>' +
        '<span class="jpm-toast-message">' +
        message +
        "</span>" +
        "</div>" +
        '<button class="jpm-toast-close">&times;</button>' +
        "</div>"
    );

    // Add to container or body
    var container = $("#jpm-toast-container");
    if (container.length === 0) {
      $("body").append('<div id="jpm-toast-container"></div>');
      container = $("#jpm-toast-container");
    }

    container.append(toast);

    // Trigger animation
    setTimeout(function () {
      toast.addClass("show");
    }, 10);

    // Auto remove after 5 seconds
    var autoRemove = setTimeout(function () {
      hideToast(toast);
    }, 5000);

    // Close button
    toast.find(".jpm-toast-close").on("click", function () {
      clearTimeout(autoRemove);
      hideToast(toast);
    });
  }

  function hideToast(toast) {
    toast.removeClass("show");
    setTimeout(function () {
      toast.remove();
    }, 300);
  }

  // AJAX for application submission (legacy form)
  $("#jpm-apply-form").on("submit", function (e) {
    e.preventDefault();
    var formData = new FormData(this);
    formData.append("action", "jpm_apply");
    formData.append("nonce", jpm_ajax.nonce);
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        showToast("Application submitted successfully!", "success");
      },
      error: function () {
        showToast("An error occurred. Please try again.", "error");
      },
    });
  });

  // AJAX for new application form submission
  $("#jpm-application-form").on("submit", function (e) {
    e.preventDefault();
    var $form = $(this);
    var $message = $("#jpm-form-message");
    var formData = new FormData(this);

    // Add action and nonce
    formData.append("action", "jpm_submit_application_form");
    formData.append(
      "jpm_application_nonce",
      $("#jpm-application-form input[name='jpm_application_nonce']").val()
    );

    // Show loading state
    var $submitBtn = $form.find('button[type="submit"]');
    var originalText = $submitBtn.text();
    $submitBtn
      .prop("disabled", true)
      .html(originalText + ' <span class="jpm-loading-spinner"></span>');
    $message.removeClass("success error").html("");

    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      timeout: 60000, // 60 seconds timeout for file uploads
      success: function (response) {
        // Handle both JSON and string responses
        if (typeof response === "string") {
          try {
            response = JSON.parse(response);
          } catch (e) {
            console.error("Failed to parse response:", response);
            showToast(
              "Invalid response from server. Please try again.",
              "error"
            );
            return;
          }
        }

        if (response.success) {
          // Show success toast
          showToast(
            response.data.message || "Application submitted successfully!",
            "success"
          );

          // Also update inline message
          $message
            .addClass("success")
            .html(
              "<p>" +
                (response.data.message ||
                  "Application submitted successfully!") +
                "</p>"
            );

          // Reset form
          $form[0].reset();

          // Scroll to message
          $("html, body").animate(
            {
              scrollTop: $message.offset().top - 100,
            },
            500
          );
        } else {
          // Show error toast
          showToast(
            response.data.message || "An error occurred. Please try again.",
            "error"
          );

          // Also update inline message
          $message
            .addClass("error")
            .html(
              "<p>" +
                (response.data.message ||
                  "An error occurred. Please try again.") +
                "</p>"
            );
        }
      },
      error: function (xhr, status, error) {
        var errorMessage = "An error occurred. Please try again.";

        // Try to get error message from response
        if (xhr.responseJSON) {
          if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errorMessage = xhr.responseJSON.data.message;
          } else if (xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
          }
        } else if (xhr.responseText) {
          // Try to parse HTML error response
          try {
            var parser = new DOMParser();
            var doc = parser.parseFromString(xhr.responseText, "text/html");
            var errorDiv = doc.querySelector(".error, .notice-error");
            if (errorDiv) {
              errorMessage = errorDiv.textContent.trim();
            }
          } catch (e) {
            // Ignore parsing errors
          }
        }

        // Log error for debugging
        console.error("AJAX Error:", {
          status: xhr.status,
          statusText: xhr.statusText,
          response: xhr.responseJSON || xhr.responseText,
          error: error,
        });

        // Show error toast
        showToast(errorMessage, "error");

        // Also update inline message
        $message.addClass("error").html("<p>" + errorMessage + "</p>");
      },
      complete: function () {
        var $submitBtn = $form.find('button[type="submit"]');
        $submitBtn.prop("disabled", false).html("Submit Application");
      },
    });
  });

  // Polling for status
  setInterval(function () {
    $.post(
      jpm_ajax.ajax_url,
      { action: "jpm_get_status", nonce: jpm_ajax.nonce },
      function (data) {
        $("#jpm-status-updates").html(data); // Update DOM
      }
    );
  }, 30000);

  // Latest Jobs Modal Functionality
  // Cache for job details
  const jobDetailsCache = {};

  // Open modal on Quick View button click
  $(document).on("click", ".jpm-btn-quick-view", function (e) {
    e.preventDefault();
    const jobId = $(this).data("job-id");

    if (!jobId) {
      return;
    }

    const $modal = $("#jpm-job-modal");
    const $modalBody = $modal.find(".jpm-modal-body");
    const $loading = $modalBody.find(".jpm-modal-loading");
    const $content = $modalBody.find(".jpm-modal-job-content");

    // Show modal
    $modal.addClass("active");
    $("body").css("overflow", "hidden");

    // Check if job details are cached
    if (jobDetailsCache[jobId]) {
      // Use cached data
      $loading.hide();
      $content.html(jobDetailsCache[jobId]).fadeIn();
      return;
    }

    // Show loading, hide content
    $loading.show();
    $content.hide().empty();

    // Fetch job details via AJAX
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: {
        action: "jpm_get_job_details",
        job_id: jobId,
        nonce: jpm_ajax.nonce,
      },
      success: function (response) {
        $loading.hide();

        if (response.success && response.data.html) {
          // Cache the job details
          jobDetailsCache[jobId] = response.data.html;
          $content.html(response.data.html).fadeIn();
        } else {
          const errorHtml =
            '<p class="jpm-error">' +
            (response.data?.message || "Failed to load job details.") +
            "</p>";
          $content.html(errorHtml).fadeIn();
        }
      },
      error: function () {
        $loading.hide();
        const errorHtml =
          '<p class="jpm-error">An error occurred while loading job details. Please try again.</p>';
        $content.html(errorHtml).fadeIn();
      },
    });
  });

  // Close modal
  function closeModal() {
    const $modal = $("#jpm-job-modal");
    $modal.removeClass("active");
    $("body").css("overflow", "");
    $modal.find(".jpm-modal-job-content").empty();
  }

  // Close on close button click
  $(document).on("click", ".jpm-modal-close", closeModal);

  // Close on overlay click
  $(document).on("click", ".jpm-modal-overlay", closeModal);

  // Close on ESC key
  $(document).on("keydown", function (e) {
    if (e.key === "Escape" && $("#jpm-job-modal").hasClass("active")) {
      closeModal();
    }
  });

  // Real-time AJAX search with debounce for all_jobs shortcode
  let searchTimeout;
  const $searchInput = $("#jpm_search");
  const $filterForm = $(".jpm-filter-form");
  const $jobsGrid = $(".jpm-latest-jobs");
  const $resultsCount = $(".jpm-jobs-results-count");
  const $pagination = $(".jpm-jobs-pagination");
  const $noJobs = $(".jpm-no-jobs");

  // Debounced search function
  function performSearch(resetPage = false) {
    const searchTerm = $searchInput.val();
    const locationFilter = $("#jpm_location").val() || "";
    const companyFilter = $("#jpm_company").val() || "";
    const currentPage = resetPage ? 1 : getCurrentPage();

    // Show loading state
    $jobsGrid.html(
      '<div class="jpm-loading" style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Searching jobs...</p></div>'
    );

    // Perform AJAX search
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: {
        action: "jpm_filter_jobs",
        search: searchTerm,
        location: locationFilter,
        company: companyFilter,
        per_page: 12, // Default per page
        paged: currentPage,
        nonce: jpm_ajax.nonce,
      },
      success: function (response) {
        // Hide search indicator
        $(".jpm-search-indicator").hide();

        if (response.success) {
          // Update jobs grid
          if (response.data.html) {
            $jobsGrid.html(response.data.html);
          } else {
            $jobsGrid.html(
              '<div class="jpm-no-jobs"><p>No jobs found matching your criteria.</p></div>'
            );
          }

          // Update results count
          const total = response.data.total || 0;
          const perPage = 12;
          const currentPageNum = currentPage;
          const start = (currentPageNum - 1) * perPage + 1;
          const end = Math.min(currentPageNum * perPage, total);

          if (total > 0) {
            $resultsCount.html(
              "<p>Showing " + start + "-" + end + " of " + total + " jobs</p>"
            );
          } else {
            $resultsCount.html("<p>No jobs found.</p>");
          }

          // Update pagination (if needed, you can generate pagination HTML here)
          // For now, we'll keep the existing pagination structure
          updatePagination(response.data.pages || 1, currentPageNum);

          // Update URL without reload
          updateURL(searchTerm, locationFilter, companyFilter, currentPageNum);
        } else {
          $jobsGrid.html(
            '<div class="jpm-no-jobs"><p>' +
              (response.data?.message ||
                "An error occurred. Please try again.") +
              "</p></div>"
          );
        }
      },
      error: function () {
        // Hide search indicator
        $(".jpm-search-indicator").hide();
        $jobsGrid.html(
          '<div class="jpm-no-jobs"><p>An error occurred while searching. Please try again.</p></div>'
        );
      },
    });
  }

  // Get current page from URL or default to 1
  function getCurrentPage() {
    const urlParams = new URLSearchParams(window.location.search);
    return parseInt(urlParams.get("jpm_page")) || 1;
  }

  // Update URL without reload
  function updateURL(search, location, company, page) {
    const url = new URL(window.location.href);
    url.searchParams.delete("jpm_search");
    url.searchParams.delete("jpm_location");
    url.searchParams.delete("jpm_company");
    url.searchParams.delete("jpm_page");

    if (search) {
      url.searchParams.set("jpm_search", search);
    }
    if (location) {
      url.searchParams.set("jpm_location", location);
    }
    if (company) {
      url.searchParams.set("jpm_company", company);
    }
    if (page > 1) {
      url.searchParams.set("jpm_page", page);
    }

    window.history.pushState({}, "", url);
  }

  // Update pagination HTML
  function updatePagination(totalPages, currentPage) {
    if (totalPages <= 1) {
      $pagination.html("");
      return;
    }

    let paginationHTML = '<ul class="page-numbers">';

    // Previous button
    if (currentPage > 1) {
      paginationHTML +=
        '<li><a href="?jpm_page=' +
        (currentPage - 1) +
        getFilterParams() +
        '" class="prev page-numbers">&laquo; Previous</a></li>';
    }

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
      if (
        i === 1 ||
        i === totalPages ||
        (i >= currentPage - 1 && i <= currentPage + 1)
      ) {
        if (i === currentPage) {
          paginationHTML +=
            '<li><span class="page-numbers current">' + i + "</span></li>";
        } else {
          paginationHTML +=
            '<li><a href="?jpm_page=' +
            i +
            getFilterParams() +
            '" class="page-numbers">' +
            i +
            "</a></li>";
        }
      } else if (i === currentPage - 2 || i === currentPage + 2) {
        paginationHTML += '<li><span class="page-numbers dots">…</span></li>';
      }
    }

    // Next button
    if (currentPage < totalPages) {
      paginationHTML +=
        '<li><a href="?jpm_page=' +
        (currentPage + 1) +
        getFilterParams() +
        '" class="next page-numbers">Next &raquo;</a></li>';
    }

    paginationHTML += "</ul>";
    $pagination.html(paginationHTML);
  }

  // Get filter parameters for pagination links
  function getFilterParams() {
    const search = $searchInput.val() || "";
    const location = $("#jpm_location").val() || "";
    const company = $("#jpm_company").val() || "";
    let params = "";

    if (search) {
      params += "&jpm_search=" + encodeURIComponent(search);
    }
    if (location) {
      params += "&jpm_location=" + encodeURIComponent(location);
    }
    if (company) {
      params += "&jpm_company=" + encodeURIComponent(company);
    }

    return params;
  }

  // Real-time search with debounce (only for search input)
  if ($searchInput.length) {
    $searchInput.on("input", function () {
      clearTimeout(searchTimeout);
      const searchValue = $(this).val();
      const $indicator = $(".jpm-search-indicator");

      // Show indicator when typing
      if (searchValue.length > 0) {
        $indicator.show().text("Type to search...");
      } else {
        $indicator.hide();
      }

      // Debounce: wait 500ms after user stops typing
      searchTimeout = setTimeout(function () {
        $indicator.text("Searching...");
        performSearch(true); // Reset to page 1 when searching
      }, 500);
    });

    // Hide indicator when search is complete
    $searchInput.on("blur", function () {
      setTimeout(function () {
        $(".jpm-search-indicator").hide();
      }, 1000);
    });
  }

  // Filter button click (for location and company filters)
  if ($filterForm.length) {
    $filterForm.on("submit", function (e) {
      e.preventDefault();
      clearTimeout(searchTimeout); // Clear any pending search
      performSearch(true); // Reset to page 1 when filtering
    });
  }

  // Reset button
  $(document).on("click", ".jpm-btn-reset", function (e) {
    e.preventDefault();
    clearTimeout(searchTimeout);
    $searchInput.val("");
    $("#jpm_location").val("");
    $("#jpm_company").val("");
    window.location.href = window.location.pathname;
  });

  // Handle pagination clicks (prevent default and use AJAX)
  $(document).on("click", ".jpm-jobs-pagination .page-numbers", function (e) {
    if ($(this).hasClass("current") || $(this).hasClass("dots")) {
      e.preventDefault();
      return;
    }

    e.preventDefault();
    const href = $(this).attr("href");
    if (href) {
      const url = new URL(href, window.location.origin);
      const page = url.searchParams.get("jpm_page") || 1;
      const search = url.searchParams.get("jpm_search") || "";
      const location = url.searchParams.get("jpm_location") || "";
      const company = url.searchParams.get("jpm_company") || "";

      // Update form values
      $searchInput.val(search);
      $("#jpm_location").val(location);
      $("#jpm_company").val(company);

      // Perform search with new page
      $jobsGrid.html(
        '<div class="jpm-loading" style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Loading jobs...</p></div>'
      );

      $.ajax({
        url: jpm_ajax.ajax_url,
        type: "POST",
        data: {
          action: "jpm_filter_jobs",
          search: search,
          location: location,
          company: company,
          per_page: 12,
          paged: page,
          nonce: jpm_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            if (response.data.html) {
              $jobsGrid.html(response.data.html);
            } else {
              $jobsGrid.html(
                '<div class="jpm-no-jobs"><p>No jobs found matching your criteria.</p></div>'
              );
            }

            const total = response.data.total || 0;
            const perPage = 12;
            const start = (page - 1) * perPage + 1;
            const end = Math.min(page * perPage, total);

            if (total > 0) {
              $resultsCount.html(
                "<p>Showing " + start + "-" + end + " of " + total + " jobs</p>"
              );
            } else {
              $resultsCount.html("<p>No jobs found.</p>");
            }

            updatePagination(response.data.pages || 1, parseInt(page));
            updateURL(search, location, company, parseInt(page));

            // Scroll to top of jobs grid
            $("html, body").animate(
              {
                scrollTop: $jobsGrid.offset().top - 100,
              },
              500
            );
          }
        },
        error: function () {
          $jobsGrid.html(
            '<div class="jpm-no-jobs"><p>An error occurred. Please try again.</p></div>'
          );
        },
      });
    }
  });
});
