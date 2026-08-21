// User Management Scripts

// Open add modal
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New User';
    document.getElementById('submitBtnText').textContent = 'Add User';
    document.getElementById('formAction').value = 'add';
    document.getElementById('userId').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('password').required = true;
    document.getElementById('passwordHint').textContent = 'Minimum 6 characters';
    document.getElementById('dlcField').style.display = 'none';
    document.getElementById('atcField').style.display = 'none';
    document.getElementById('userModal').classList.add('active');
}

// Close modal
function closeModal() {
    document.getElementById('userModal').classList.remove('active');
    document.getElementById('userForm').reset();
}

// Handle role change
function handleRoleChange() {
    const role = document.getElementById('role').value;
    const dlcField = document.getElementById('dlcField');
    const atcField = document.getElementById('atcField');
    const dlcSelect = document.getElementById('dlc_id');
    const atcSelect = document.getElementById('atc_id');
    const dlcRequired = document.getElementById('dlcRequired');
    const atcRequired = document.getElementById('atcRequired');
    
    // Reset selections
    dlcSelect.value = '';
    atcSelect.value = '';
    
    if (role === 'Admin') {
        dlcField.style.display = 'none';
        atcField.style.display = 'none';
        dlcSelect.required = false;
        atcSelect.required = false;
        if (dlcRequired) dlcRequired.style.display = 'none';
        if (atcRequired) atcRequired.style.display = 'none';
    } else if (role === 'DLC Office') {
        dlcField.style.display = 'block';
        atcField.style.display = 'none';
        dlcSelect.required = true;
        atcSelect.required = false;
        if (dlcRequired) dlcRequired.style.display = 'inline';
        if (atcRequired) atcRequired.style.display = 'none';
    } else if (role === 'ATC CENTER') {
        dlcField.style.display = 'block';
        atcField.style.display = 'block';
        dlcSelect.required = true;
        atcSelect.required = true;
        if (dlcRequired) dlcRequired.style.display = 'inline';
        if (atcRequired) atcRequired.style.display = 'inline';
    } else {
        dlcField.style.display = 'none';
        atcField.style.display = 'none';
        dlcSelect.required = false;
        atcSelect.required = false;
        if (dlcRequired) dlcRequired.style.display = 'none';
        if (atcRequired) atcRequired.style.display = 'none';
    }
}

// Handle DLC change (filter ATC centers)
function handleDLCChange() {
    const dlcId = document.getElementById('dlc_id').value;
    const atcSelect = document.getElementById('atc_id');
    const options = atcSelect.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
        } else if (dlcId === '' || option.dataset.dlc === dlcId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    // Reset selection if current selection is not valid
    const currentOption = atcSelect.options[atcSelect.selectedIndex];
    if (currentOption && currentOption.dataset.dlc && currentOption.dataset.dlc !== dlcId) {
        atcSelect.value = '';
    }
}

// Edit user
async function editUser(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get');
        formData.append('id', id);
        
        const response = await fetch('', {
            method: 'POST',
            body: new URLSearchParams(formData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            const user = data.data;
            
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('submitBtnText').textContent = 'Update User';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('userId').value = user.id;
            document.getElementById('username').value = user.username;
            document.getElementById('password').value = '';
            document.getElementById('password').required = false;
            document.getElementById('passwordRequired').style.display = 'none';
            document.getElementById('passwordHint').textContent = 'Leave blank to keep current password';
            document.getElementById('name').value = user.name;
            document.getElementById('role').value = user.role;
            document.getElementById('status').value = user.status;
            document.getElementById('mobile').value = user.mobile || '';
            document.getElementById('email').value = user.email || '';
            document.getElementById('dlc_id').value = user.dlc_id || '';
            document.getElementById('atc_id').value = user.atc_id || '';
            
            // Trigger role change to show/hide fields
            handleRoleChange();
            
            // If ATC role, trigger DLC change to filter ATCs
            if (user.role === 'ATC CENTER' && user.dlc_id) {
                handleDLCChange();
            }
            
            document.getElementById('userModal').classList.add('active');
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error loading user data');
    }
}

// Delete user
async function deleteUser(id, username) {
    if (!confirm(`Are you sure you want to delete user "${username}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        const response = await fetch('', {
            method: 'POST',
            body: new URLSearchParams(formData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting user');
    }
}

// Reset password
function resetPassword(id, username) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('new_password').value = '';
    document.getElementById('resetPasswordModal').classList.add('active');
}

// Close reset password modal
function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.remove('active');
    document.getElementById('resetPasswordForm').reset();
}

// Handle user form submission
document.addEventListener('DOMContentLoaded', function() {
    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = document.getElementById('submitBtn');
            const originalText = document.getElementById('submitBtnText').textContent;
            
            submitBtn.disabled = true;
            document.getElementById('submitBtnText').textContent = 'Processing...';
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: new URLSearchParams(formData)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    submitBtn.disabled = false;
                    document.getElementById('submitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error saving user');
                submitBtn.disabled = false;
                document.getElementById('submitBtnText').textContent = originalText;
            }
        });
    }

    // Handle reset password form submission
    const resetPasswordForm = document.getElementById('resetPasswordForm');
    if (resetPasswordForm) {
        resetPasswordForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Processing...';
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: new URLSearchParams(formData)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    closeResetPasswordModal();
                } else {
                    alert('Error: ' + data.message);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Reset Password';
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error resetting password');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Reset Password';
            }
        });
    }

    // Close modals on overlay click
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });

    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });
});
