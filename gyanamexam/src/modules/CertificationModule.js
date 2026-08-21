/**
 * CertificationModule - Displays student's earned certificates
 * 
 * Features:
 * - Fetches and displays certificates from API
 * - Shows certificate metadata (exam name, date, score, grade)
 * - Displays certificate status (pending, approved, issued, revoked)
 * - Provides placeholders for download functionality
 * - Shows certificate validation information
 * 
 * Requirements: 13.1, 13.2, 13.3, 13.4, 13.5, 30.4
 */

import { APIClient } from '../services/APIClient.js';

export class CertificationModule {
  /**
   * Create CertificationModule instance
   * @param {APIClient} apiClient - API client instance
   */
  constructor(apiClient = null) {
    this.apiClient = apiClient || new APIClient();
    this.certificates = [];
    this.container = null;
  }

  /**
   * Initialize and render certificates
   * @param {HTMLElement} container - Container element to render into
   * @param {string} studentId - Student identifier
   * @returns {Promise<void>}
   */
  async initialize(container, studentId) {
    this.container = container;
    // Certificates are not yet implemented in the backend — render empty state
    try {
      this.renderLoading();
      this.certificates = [];
      this.render();
    } catch (error) {
      this.renderError(error);
    }
  }

  /**
   * Render loading state
   * @private
   */
  renderLoading() {
    if (!this.container) return;
    
    this.container.innerHTML = `
      <div class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
        <span class="ml-3 text-gray-600">Loading certificates...</span>
      </div>
    `;
  }

  /**
   * Render error state
   * @private
   * @param {Error} error - Error object
   */
  renderError(error) {
    if (!this.container) return;
    
    this.container.innerHTML = `
      <div class="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
        <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-red-800">Failed to Load Certificates</h3>
        <p class="mt-2 text-sm text-red-600">${error.message || 'An unexpected error occurred'}</p>
        <button onclick="location.reload()" class="mt-4 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
          Retry
        </button>
      </div>
    `;
  }

  /**
   * Render certificates
   */
  render() {
    if (!this.container) return;
    
    // Create certificate cards
    let certificateCards = '';
    
    if (this.certificates.length === 0) {
      certificateCards = `
        <div class="text-center py-12 bg-gray-50 rounded-lg">
          <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">No Certificates Yet</h3>
          <p class="mt-2 text-sm text-gray-500">
            Complete exams with passing scores to earn certificates.
          </p>
        </div>
      `;
    } else {
      certificateCards = this.certificates.map(cert => this.renderCertificateCard(cert)).join('');
    }
    
    this.container.innerHTML = `
      <div class="certification-module">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">My Certificates</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          ${certificateCards}
        </div>
      </div>
    `;
    
    // Attach event listeners
    this.attachEventListeners();
  }

  /**
   * Render individual certificate card
   * @private
   * @param {Object} cert - Certificate object
   * @returns {string} HTML string for certificate card
   */
  renderCertificateCard(cert) {
    const statusConfig = this.getStatusConfig(cert.status);
    const issuedDate = cert.issuedAt ? new Date(cert.issuedAt).toLocaleDateString() : 'N/A';
    const validUntil = cert.validUntil ? new Date(cert.validUntil).toLocaleDateString() : 'N/A';
    
    // Grade color
    const gradeColors = {
      'A': 'text-green-600',
      'B': 'text-blue-600',
      'C': 'text-yellow-600',
      'D': 'text-orange-600',
      'F': 'text-red-600'
    };
    const gradeColor = gradeColors[cert.grade] || 'text-gray-600';
    
    // Download button (placeholder)
    const downloadButton = cert.status === 'issued' ? `
      <button 
        class="download-cert-btn w-full mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2"
        data-cert-id="${cert.certificateId}"
        data-download-url="${cert.downloadUrl}"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        Download Certificate
      </button>
    ` : '';
    
    return `
      <div class="bg-white border-2 ${statusConfig.borderColor} rounded-lg p-6 hover:shadow-lg transition-shadow">
        <!-- Certificate Header -->
        <div class="flex items-start justify-between mb-4">
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
              <svg class="h-8 w-8 ${statusConfig.iconColor}" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
              <span class="px-3 py-1 text-xs font-semibold rounded-full ${statusConfig.bgColor} ${statusConfig.textColor}">
                ${statusConfig.label}
              </span>
            </div>
            <h3 class="text-lg font-bold text-gray-900">${cert.examTitle}</h3>
          </div>
        </div>
        
        <!-- Score and Grade -->
        <div class="flex items-center justify-between py-4 border-t border-b border-gray-200">
          <div class="text-center flex-1">
            <p class="text-sm text-gray-600 mb-1">Score</p>
            <p class="text-3xl font-bold text-blue-600">${cert.score}%</p>
          </div>
          <div class="h-12 w-px bg-gray-300"></div>
          <div class="text-center flex-1">
            <p class="text-sm text-gray-600 mb-1">Grade</p>
            <p class="text-3xl font-bold ${gradeColor}">${cert.grade}</p>
          </div>
        </div>
        
        <!-- Certificate Details -->
        <div class="mt-4 space-y-2 text-sm">
          <div class="flex justify-between">
            <span class="text-gray-600">Certificate ID:</span>
            <span class="text-gray-900 font-mono text-xs">${cert.certificateId}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">Issued Date:</span>
            <span class="text-gray-900 font-medium">${issuedDate}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">Valid Until:</span>
            <span class="text-gray-900 font-medium">${validUntil}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">Verification Code:</span>
            <span class="text-gray-900 font-mono text-xs font-semibold">${cert.verificationCode}</span>
          </div>
        </div>
        
        <!-- Issuer Information -->
        <div class="mt-4 pt-4 border-t border-gray-200">
          <p class="text-xs text-gray-500">Issued by</p>
          <p class="text-sm font-semibold text-gray-900">${cert.metadata.issuer}</p>
          <p class="text-xs text-gray-600">${cert.metadata.signatoryName}, ${cert.metadata.signatoryTitle}</p>
        </div>
        
        ${downloadButton}
        
        <!-- Verification Info -->
        ${cert.status === 'issued' ? `
          <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
            <p class="text-xs text-blue-800">
              <svg class="inline h-4 w-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
              </svg>
              This certificate can be verified using the verification code above.
            </p>
          </div>
        ` : ''}
      </div>
    `;
  }

  /**
   * Get status configuration for display
   * @private
   * @param {string} status - Certificate status
   * @returns {Object} Status configuration
   */
  getStatusConfig(status) {
    const configs = {
      'issued': {
        label: 'Issued',
        bgColor: 'bg-green-100',
        textColor: 'text-green-800',
        borderColor: 'border-green-300',
        iconColor: 'text-green-600'
      },
      'approved': {
        label: 'Approved',
        bgColor: 'bg-blue-100',
        textColor: 'text-blue-800',
        borderColor: 'border-blue-300',
        iconColor: 'text-blue-600'
      },
      'pending': {
        label: 'Pending Approval',
        bgColor: 'bg-yellow-100',
        textColor: 'text-yellow-800',
        borderColor: 'border-yellow-300',
        iconColor: 'text-yellow-600'
      },
      'revoked': {
        label: 'Revoked',
        bgColor: 'bg-red-100',
        textColor: 'text-red-800',
        borderColor: 'border-red-300',
        iconColor: 'text-red-600'
      }
    };
    
    return configs[status] || configs['pending'];
  }

  /**
   * Attach event listeners
   * @private
   */
  attachEventListeners() {
    // Download button listeners
    const downloadButtons = this.container.querySelectorAll('.download-cert-btn');
    downloadButtons.forEach(button => {
      button.addEventListener('click', (e) => {
        const certId = e.currentTarget.dataset.certId;
        const downloadUrl = e.currentTarget.dataset.downloadUrl;
        this.handleDownload(certId, downloadUrl);
      });
    });
  }

  /**
   * Handle certificate download (placeholder)
   * @private
   * @param {string} certId - Certificate ID
   * @param {string} downloadUrl - Download URL
   */
  handleDownload(certId, downloadUrl) {
    // Placeholder for download functionality
    // In production, this would trigger actual download from backend
    console.log(`Download certificate: ${certId} from ${downloadUrl}`);
    
    // Show placeholder message
    alert('Certificate download functionality will be available once backend integration is complete.');
    
    // Future implementation:
    // window.open(downloadUrl, '_blank');
    // or
    // const link = document.createElement('a');
    // link.href = downloadUrl;
    // link.download = `certificate_${certId}.pdf`;
    // link.click();
  }

  /**
   * Refresh certificates
   * @param {string} studentId - Student identifier
   * @returns {Promise<void>}
   */
  async refresh(studentId) {
    if (!this.container) return;
    await this.initialize(this.container, studentId);
  }

  /**
   * Cleanup and remove event listeners
   */
  destroy() {
    if (this.container) {
      this.container.innerHTML = '';
    }
    this.container = null;
    this.certificates = [];
  }
}
