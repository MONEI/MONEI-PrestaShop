document.addEventListener("DOMContentLoaded", function (event) {
    let currentCardId = null;
    let currentCardElement = null;
    
    // Handle delete button clicks
    const deleteButtons = document.querySelectorAll('.delete-card-btn');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            currentCardId = this.getAttribute('data-customer-card-id');
            currentCardElement = this.closest('tr');
        });
    });
    
    // Handle confirm delete
    const confirmDeleteBtn = document.getElementById('confirmDeleteCard');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function() {
            if (!currentCardId || !currentCardElement) return;
            
            // Disable button and show loading
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + 
                            (MoneiVars.removingCard || 'Removing...');
            
            // Make the delete request
            ajaxDeleteTokenizedCard(currentCardId, currentCardElement);
        });
    }
    
    // Reset state when modal is closed
    if (typeof $ !== 'undefined' && $('#deleteCardModal').length) {
        $('#deleteCardModal').on('hidden.bs.modal', function() {
            currentCardId = null;
            currentCardElement = null;
            // Reset button state
            const confirmBtn = document.getElementById('confirmDeleteCard');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = MoneiVars.confirmRemoveCard || 'Yes, remove it';
            }
        });
    }
});

function ajaxDeleteTokenizedCard(customerCardId, itemCustomerCard) {
    const deleteCustomerCardUrl = new URL(MoneiVars.indexUrl);

    const params = {
        fc: 'module',
        module: 'monei',
        controller: 'customerCards',
        action: 'deleteCustomerCard',
        ajax: true,
        customerCardId
    };

    deleteCustomerCardUrl.search = new URLSearchParams(params).toString();

    fetch(deleteCustomerCardUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        // Hide the modal
        if (typeof $ !== 'undefined' && $('#deleteCardModal').length) {
            $('#deleteCardModal').modal('hide');
        }
        
        if (data.success) {
            // Show success message
            showNotification('success', MoneiVars.successfullyRemovedCard || 'Card removed successfully');
            
            // Remove the card row
            itemCustomerCard.remove();
            
            // Check if table is now empty
            const tbody = document.getElementById('credit_card_list');
            if (tbody && tbody.querySelectorAll('tr').length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center">' + 
                    (MoneiVars.noSavedCards || 'You don\'t have any saved credit cards yet.') + 
                    '</td></tr>';
            }
        } else {
            showNotification('error', data.error || MoneiVars.errorRemovingCard || 'An error occurred while removing the card');
        }
    })
    .catch(error => {
        // Hide the modal
        if (typeof $ !== 'undefined' && $('#deleteCardModal').length) {
            $('#deleteCardModal').modal('hide');
        }
        showNotification('error', MoneiVars.unexpectedError || 'An unexpected error occurred.');
        console.error('Error:', error);
    });
}

// Helper function to show notifications
function showNotification(type, message) {
    // Always try to use PrestaShop's core notification system first
    if (typeof prestashop !== 'undefined' && prestashop.emit) {
        prestashop.emit('showNotification', {
            type: type,
            message: message
        });
        return;
    }
    
    // Fallback to Bootstrap alert if PrestaShop notification system is not available
    const alertType = type === 'error' ? 'danger' : type;
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${alertType} alert-dismissible fade show`;
    alertDiv.setAttribute('role', 'alert');
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    `;
    
    // Find a suitable container - check for PrestaShop's notification container first
    const containers = [
        '#notifications',
        '.notifications-container',
        '.page-content',
        '#content-wrapper',
        'main',
        '.container'
    ];
    
    let container = null;
    for (const selector of containers) {
        container = document.querySelector(selector);
        if (container) break;
    }
    
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
        
        // Scroll to alert for visibility
        alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.classList.remove('show');
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 150);
            }
        }, 5000);
    }
}
