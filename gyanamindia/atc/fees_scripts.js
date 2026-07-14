/**
 * Fees Management - JavaScript Functions
 * Gyanam India Educational Services
 */

let currentStudentId = null;
let currentStudentData = null;

// View student details
async function viewStudentDetails(id) {
    console.log('viewStudentDetails called with id:', id);
    currentStudentId = id;

    try {
        const formData = new FormData();
        formData.append('action', 'get_student');
        formData.append('id', id);

        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();

        console.log('Student data received:', result);

        if (result.success && result.data) {
            currentStudentData = result.data;
            const student = result.data;
            const fullName = `${student.first_name} ${student.middle_name ? student.middle_name + ' ' : ''}${student.last_name}`;

            const courseFees = parseFloat(student.course_fees || student.fees_total || 0);
            const discountAmount = parseFloat(student.discount_amount || 0);
            const netPayable = parseFloat(student.net_payable || student.fees_total || 0);
            const feesPaid = parseFloat(student.fees_paid || 0);
            const netBalance = netPayable - feesPaid;
            const paidPercentage = netPayable > 0 ? Math.round((feesPaid / netPayable) * 100) : 0;

            // Get payment history
            const historyFormData = new FormData();
            historyFormData.append('action', 'get_payment_history');
            historyFormData.append('student_id', id);

            const historyResponse = await fetch('', { method: 'POST', body: historyFormData });
            const historyResult = await historyResponse.json();
            const payments = historyResult.success ? historyResult.data : [];

            // Get remarks history
            const remarksFormData = new FormData();
            remarksFormData.append('action', 'get_remarks_history');
            remarksFormData.append('student_id', id);

            const remarksResponse = await fetch('', { method: 'POST', body: remarksFormData });
            const remarksResult = await remarksResponse.json();
            const remarks = remarksResult.success ? remarksResult.data : [];

            // Build payment receipts table
            let paymentTableHTML = '';
            if (payments.length > 0) {
                paymentTableHTML = `
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Paid Fees Receipts</h4>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="data-table" style="font-size: 0.85rem;">
                                <thead>
                                    <tr>
                                        <th>Installment</th>
                                        <th>Receipt No</th>
                                        <th>Amount</th>
                                        <th>Paid Date</th>
                                        <th>Payment Mode</th>
                                        <th>Description</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${payments.map(payment => `
                                        <tr>
                                            <td><strong>#${payment.installment_no || 1}</strong></td>
                                            <td><span style="color: var(--primary-600); font-weight: 600;">${payment.receipt_no}</span></td>
                                            <td><strong style="color: var(--success-600);">₹ ${parseFloat(payment.amount).toFixed(2)}</strong></td>
                                            <td>${new Date(payment.payment_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                                            <td>${payment.payment_mode}</td>
                                            <td>${payment.description || payment.remarks || '-'}</td>
                                            <td>
                                                <div style="display:flex;gap:.4rem;">
                                                    <a href="fee_receipt.php?receipt_no=${encodeURIComponent(payment.receipt_no)}" target="_blank" class="btn-icon" title="Print Receipt" style="color:#4361ee;">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                                    </a>
                                                    <button class="btn-icon danger" onclick="deletePayment(${payment.id})" title="Delete Payment">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                paymentTableHTML = `
                    <div class="detail-section">
                        <h4>Paid Fees Receipts</h4>
                        <p style="text-align: center; color: var(--text-secondary); padding: 2rem;">No payments recorded yet.</p>
                    </div>
                `;
            }

            // Build remarks history
            let remarksHTML = '';
            if (remarks.length > 0) {
                remarksHTML = `
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Remarks History</h4>
                            <button class="btn-primary" onclick="openAddRemarkModal()" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Remark
                            </button>
                        </div>
                        <div class="remarks-timeline">
                            ${remarks.map(remark => `
                                <div class="remark-item">
                                    <div class="remark-header">
                                        <span class="remark-type remark-type-${remark.remark_type.toLowerCase().replace(' ', '-')}">${remark.remark_type}</span>
                                        <span class="remark-time">${new Date(remark.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>
                                    </div>
                                    <div class="remark-text">${remark.remark_text}</div>
                                    ${remark.created_by_name ? `<div class="remark-author">By: ${remark.created_by_name}</div>` : ''}
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else {
                remarksHTML = `
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Remarks History</h4>
                            <button class="btn-primary" onclick="openAddRemarkModal()" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Remark
                            </button>
                        </div>
                        <p style="text-align: center; color: var(--text-secondary); padding: 2rem;">No remarks added yet.</p>
                    </div>
                `;
            }

            document.getElementById('detailsContent').innerHTML = `
                <div class="student-details-grid">
                    <div class="detail-section">
                        <h4>Student Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Roll Number</span>
                                <span class="detail-value">${student.roll_no}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Full Name</span>
                                <span class="detail-value">${fullName}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Course</span>
                                <span class="detail-value">${student.course}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Mobile</span>
                                <span class="detail-value">${student.mobile}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Fees Details</h4>
                            <button class="btn-primary" onclick="openUpdateFeesModal()" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Update Fees
                            </button>
                        </div>
                        <div class="fees-details-grid">
                            <div class="fees-detail-item">
                                <span class="fees-detail-label">Course Fees</span>
                                <span class="fees-detail-value">₹ ${courseFees.toFixed(2)}</span>
                            </div>
                            <div class="fees-detail-item">
                                <span class="fees-detail-label">Discount</span>
                                <span class="fees-detail-value" style="color: var(--success-600);">- ₹ ${discountAmount.toFixed(2)}</span>
                            </div>
                            ${student.discount_reason ? `
                            <div class="fees-detail-item full-width">
                                <span class="fees-detail-label">Discount Reason</span>
                                <span class="fees-detail-value">${student.discount_reason}</span>
                            </div>
                            ` : ''}
                            <div class="fees-detail-item highlight">
                                <span class="fees-detail-label">Net Payable Fees</span>
                                <span class="fees-detail-value">₹ ${netPayable.toFixed(2)}</span>
                            </div>
                            <div class="fees-detail-item paid">
                                <span class="fees-detail-label">Amount Paid</span>
                                <span class="fees-detail-value">₹ ${feesPaid.toFixed(2)}</span>
                            </div>
                            <div class="fees-detail-item pending">
                                <span class="fees-detail-label">Balance Pending</span>
                                <span class="fees-detail-value">₹ ${netBalance.toFixed(2)}</span>
                            </div>
                        </div>
                        <div class="fees-progress-container">
                            <div class="fees-progress-bar">
                                <div class="fees-progress-fill" style="width: ${paidPercentage}%"></div>
                            </div>
                            <div class="fees-progress-text">${paidPercentage}% Paid</div>
                        </div>
                    </div>
                    
                    ${paymentTableHTML}
                    ${remarksHTML}
                </div>
            `;

            // Show/hide record payment button
            const recordPaymentBtn = document.getElementById('recordPaymentBtn');
            if (netBalance > 0) {
                recordPaymentBtn.style.display = 'inline-flex';
            } else {
                recordPaymentBtn.style.display = 'none';
            }

            document.getElementById('detailsModal').classList.add('active');
            console.log('Details modal opened successfully');
        } else {
            console.error('Failed to load student details:', result);
            alert('Error loading student details');
        }
    } catch (error) {
        console.error('Error in viewStudentDetails:', error);
        alert('Error loading student details: ' + error.message);
    }
}

// Close details modal
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('active');
}

// Record payment from details modal
function recordPaymentFromDetails() {
    closeDetailsModal();
    recordPayment(currentStudentId);
}

// Record payment
async function recordPayment(id) {
    console.log('recordPayment called with id:', id);
    currentStudentId = id;

    try {
        const formData = new FormData();
        formData.append('action', 'get_student');
        formData.append('id', id);

        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success && result.data) {
            const student = result.data;
            const fullName = `${student.first_name} ${student.middle_name ? student.middle_name + ' ' : ''}${student.last_name}`;

            document.getElementById('payment_student_id').value = student.id;
            document.getElementById('pmAvatar').textContent = fullName.charAt(0).toUpperCase();
            document.getElementById('paymentStudentName').textContent = fullName;
            document.getElementById('paymentStudentRoll').textContent = `Roll No: ${student.roll_no}  ·  ${student.course}`;
            const pending = parseFloat(student.fees_pending || 0);
            const netPay = parseFloat(student.net_payable || student.fees_total || 0);
            const paidSoFar = parseFloat(student.fees_paid || 0);
            document.getElementById('paymentPendingAmount').textContent = `₹${pending.toLocaleString('en-IN')}`;
            document.getElementById('paymentTotalFees').textContent = `₹${netPay.toLocaleString('en-IN')}`;
            document.getElementById('paymentPaidSoFar').textContent = `₹${paidSoFar.toLocaleString('en-IN')}`;

            // Set max amount to pending amount
            document.getElementById('amount').max = student.fees_pending;
            document.getElementById('amount').value = '';
            document.getElementById('payment_mode').value = '';
            document.getElementById('transaction_ref').value = '';
            document.getElementById('remarks').value = '';
            document.getElementById('description').value = '';

            document.getElementById('paymentModal').classList.add('active');
            console.log('Payment modal opened successfully');
        } else {
            console.error('Failed to load student data:', result);
            alert('Error loading student data');
        }
    } catch (error) {
        console.error('Error in recordPayment:', error);
        alert('Error loading student data: ' + error.message);
    }
}

// Close payment modal
function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('active');
}

// Show receipt — opens the professional receipt page in a popup
function showReceipt(paymentData) {
    const receiptNo = paymentData.receipt_no;
    const paymentId = paymentData.payment_id || '';
    // Open the professional receipt in a print-ready popup
    const url = `fee_receipt.php?receipt_no=${encodeURIComponent(receiptNo)}&print=1`;
    const popup = window.open(url, 'receipt_print',
        'width=850,height=700,scrollbars=yes,resizable=yes');
    if (popup) popup.focus();
    // Show a small inline confirmation in receipt area too (fallback)
    const receiptEl = document.getElementById('receiptContent');
    if (receiptEl) {
        receiptEl.innerHTML = `
            <div style="text-align:center;padding:2rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" style="width:48px;height:48px;margin:0 auto 1rem;display:block;"><polyline points="20 6 9 17 4 12"/></svg>
                <p style="font-size:1.1rem;font-weight:700;color:#111;">Payment Recorded!</p>
                <p style="color:#6b7280;margin:.5rem 0;">Receipt <strong>${receiptNo}</strong> opened in a new window.</p>
                <button class="btn-primary" onclick="window.open('fee_receipt.php?receipt_no=${encodeURIComponent(receiptNo)}','_blank')" style="margin-top:1rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Open Receipt Again
                </button>
            </div>`;
        document.getElementById('receiptModal').classList.add('active');
    } else {
        setTimeout(() => location.reload(), 400);
    }
}

// Close the confirmation/receipt modal (reloads page)
function closeReceiptModal() {
    const modal = document.getElementById('receiptModal');
    if (modal) modal.classList.remove('active');
    location.reload();
}

// printReceipt - no-op since print is handled by the popup window
function printReceipt() {
    // The actual receipt was already opened in a popup with print=1
    // This is a fallback for the Print button in the confirmation modal
    const links = document.querySelectorAll('#receiptContent a.btn-primary');
    if (links.length > 0) { links[0].click(); }
}


function openUpdateFeesModal() {
    if (!currentStudentData) return;

    document.getElementById('update_student_id').value = currentStudentData.id;
    document.getElementById('update_course_fees').value = currentStudentData.course_fees || currentStudentData.fees_total || 0;
    document.getElementById('update_discount_amount').value = currentStudentData.discount_amount || 0;
    document.getElementById('update_discount_reason').value = currentStudentData.discount_reason || '';

    updateNetPayable();

    document.getElementById('updateFeesModal').classList.add('active');
}

// Close update fees modal
function closeUpdateFeesModal() {
    document.getElementById('updateFeesModal').classList.remove('active');
}

// Calculate net payable
function updateNetPayable() {
    const courseFees = parseFloat(document.getElementById('update_course_fees').value) || 0;
    const discountAmount = parseFloat(document.getElementById('update_discount_amount').value) || 0;
    const netPayable = courseFees - discountAmount;
    document.getElementById('calculated_net_payable').textContent = `₹ ${netPayable.toFixed(2)}`;
}

// Open add remark modal
function openAddRemarkModal() {
    if (!currentStudentData) return;

    document.getElementById('remark_student_id').value = currentStudentData.id;
    document.getElementById('remark_type').value = 'General';
    document.getElementById('remark_text').value = '';

    document.getElementById('addRemarkModal').classList.add('active');
}

// Close add remark modal
function closeAddRemarkModal() {
    document.getElementById('addRemarkModal').classList.remove('active');
}

// Delete payment
async function deletePayment(paymentId) {
    if (!confirm('Are you sure you want to delete this payment? This will update the student\'s balance.')) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'delete_payment');
        formData.append('payment_id', paymentId);

        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            alert('Payment deleted successfully');
            closeDetailsModal();
            // Reload page to show updated data
            setTimeout(() => {
                window.location.reload();
            }, 500);
        } else {
            alert(result.message || 'Error deleting payment');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting payment. Please try again.');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    console.log('Fees Management Scripts Loaded');

    // Payment form submission
    const paymentForm = document.getElementById('paymentForm');
    if (paymentForm) {
        paymentForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const amount = parseFloat(document.getElementById('amount').value);
            const pendingAmountText = document.getElementById('paymentPendingAmount').textContent.replace('₹', '').replace(/,/g, '').trim();
            const pendingAmount = parseFloat(pendingAmountText);

            if (isNaN(amount) || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            if (isNaN(pendingAmount)) {
                alert('Unable to determine pending amount. Please try again.');
                return;
            }

            if (amount > pendingAmount) {
                alert(`Payment amount cannot exceed pending amount of ₹${pendingAmount.toLocaleString('en-IN')}`);
                return;
            }

            const paymentMode = document.getElementById('payment_mode').value;
            if (!paymentMode) {
                alert('Please select a payment mode');
                return;
            }

            const submitBtn = document.getElementById('paymentSubmitBtn');
            const originalText = document.getElementById('paymentSubmitBtnText').textContent;
            submitBtn.disabled = true;
            document.getElementById('paymentSubmitBtnText').textContent = 'Processing...';

            try {
                const formData = new FormData(this);
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    closePaymentModal();
                    showReceipt(result);
                    // Page will reload only when user closes the receipt modal
                } else {
                    alert(result.message || 'Error recording payment');
                    submitBtn.disabled = false;
                    document.getElementById('paymentSubmitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error recording payment. Please check your connection and try again.');
                submitBtn.disabled = false;
                document.getElementById('paymentSubmitBtnText').textContent = originalText;
            }
        });
    }

    // Update fees form submission
    const updateFeesForm = document.getElementById('updateFeesForm');
    if (updateFeesForm) {
        updateFeesForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const submitBtn = document.getElementById('updateFeesSubmitBtn');
            const originalText = document.getElementById('updateFeesSubmitBtnText').textContent;
            submitBtn.disabled = true;
            document.getElementById('updateFeesSubmitBtnText').textContent = 'Updating...';

            try {
                const formData = new FormData(this);
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    closeUpdateFeesModal();
                    alert('Fees structure updated successfully');
                    viewStudentDetails(currentStudentId);
                } else {
                    alert(result.message || 'Error updating fees structure');
                    submitBtn.disabled = false;
                    document.getElementById('updateFeesSubmitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error updating fees structure');
                submitBtn.disabled = false;
                document.getElementById('updateFeesSubmitBtnText').textContent = originalText;
            }
        });
    }

    // Add remark form submission
    const addRemarkForm = document.getElementById('addRemarkForm');
    if (addRemarkForm) {
        addRemarkForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const submitBtn = document.getElementById('addRemarkSubmitBtn');
            const originalText = document.getElementById('addRemarkSubmitBtnText').textContent;
            submitBtn.disabled = true;
            document.getElementById('addRemarkSubmitBtnText').textContent = 'Adding...';

            try {
                const formData = new FormData(this);
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    closeAddRemarkModal();
                    alert('Remark added successfully');
                    viewStudentDetails(currentStudentId);
                } else {
                    alert(result.message || 'Error adding remark');
                    submitBtn.disabled = false;
                    document.getElementById('addRemarkSubmitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error adding remark');
                submitBtn.disabled = false;
                document.getElementById('addRemarkSubmitBtnText').textContent = originalText;
            }
        });
    }

    // Add click handlers for modal overlays to close on outside click
    const modals = ['detailsModal', 'paymentModal', 'updateFeesModal', 'addRemarkModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        }
    });

    // Receipt modal should NOT close on outside click - user must click close button
    const receiptModal = document.getElementById('receiptModal');
    if (receiptModal) {
        receiptModal.addEventListener('click', function (e) {
            // Do nothing - prevent closing on outside click
            e.stopPropagation();
        });
    }

    // Add escape key handler to close modals (except receipt modal)
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal && modal.classList.contains('active')) {
                    modal.classList.remove('active');
                }
            });
            // Receipt modal will NOT close on Escape key
        }
    });
});
