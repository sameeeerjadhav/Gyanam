/**
 * Performance Monitor - Utility for monitoring and logging performance metrics
 * 
 * Features:
 * - Measure operation durations
 * - Track performance metrics
 * - Log performance data
 * - Identify performance bottlenecks
 * 
 * Requirements: 19.1, 19.2, 19.3
 */

export class PerformanceMonitor {
  constructor() {
    this.metrics = new Map();
    this.thresholds = {
      questionTransition: 100, // ms
      paletteUpdate: 50, // ms
      initialRender: 1000 // ms
    };
  }

  /**
   * Start measuring an operation
   * @param {string} operationName - Name of the operation
   * @returns {number} Start timestamp
   */
  startMeasure(operationName) {
    const startTime = performance.now();
    this.metrics.set(`${operationName}_start`, startTime);
    return startTime;
  }

  /**
   * End measuring an operation and log the duration
   * @param {string} operationName - Name of the operation
   * @param {boolean} logWarning - Log warning if threshold exceeded
   * @returns {number} Duration in milliseconds
   */
  endMeasure(operationName, logWarning = true) {
    const endTime = performance.now();
    const startTime = this.metrics.get(`${operationName}_start`);
    
    if (!startTime) {
      console.warn(`No start time found for operation: ${operationName}`);
      return 0;
    }

    const duration = endTime - startTime;
    
    // Store metric
    if (!this.metrics.has(operationName)) {
      this.metrics.set(operationName, []);
    }
    this.metrics.get(operationName).push(duration);

    // Check threshold
    const threshold = this.thresholds[operationName];
    if (threshold && duration > threshold && logWarning) {
      console.warn(
        `Performance warning: ${operationName} took ${duration.toFixed(2)}ms (threshold: ${threshold}ms)`
      );
    }

    // Clean up start time
    this.metrics.delete(`${operationName}_start`);

    return duration;
  }

  /**
   * Measure a function execution
   * @param {string} operationName - Name of the operation
   * @param {Function} fn - Function to measure
   * @returns {*} Function return value
   */
  async measure(operationName, fn) {
    this.startMeasure(operationName);
    try {
      const result = await fn();
      this.endMeasure(operationName);
      return result;
    } catch (error) {
      this.endMeasure(operationName);
      throw error;
    }
  }

  /**
   * Get statistics for an operation
   * @param {string} operationName - Name of the operation
   * @returns {Object} Statistics object
   */
  getStats(operationName) {
    const measurements = this.metrics.get(operationName);
    
    if (!measurements || measurements.length === 0) {
      return null;
    }

    const sorted = [...measurements].sort((a, b) => a - b);
    const sum = sorted.reduce((acc, val) => acc + val, 0);
    const avg = sum / sorted.length;
    const min = sorted[0];
    const max = sorted[sorted.length - 1];
    const median = sorted[Math.floor(sorted.length / 2)];
    const p95 = sorted[Math.floor(sorted.length * 0.95)];
    const p99 = sorted[Math.floor(sorted.length * 0.99)];

    return {
      count: sorted.length,
      avg: avg.toFixed(2),
      min: min.toFixed(2),
      max: max.toFixed(2),
      median: median.toFixed(2),
      p95: p95.toFixed(2),
      p99: p99.toFixed(2)
    };
  }

  /**
   * Get all statistics
   * @returns {Object} All statistics
   */
  getAllStats() {
    const stats = {};
    
    for (const [key, value] of this.metrics.entries()) {
      if (!key.endsWith('_start') && Array.isArray(value)) {
        stats[key] = this.getStats(key);
      }
    }

    return stats;
  }

  /**
   * Log performance report
   */
  logReport() {
    console.group('Performance Report');
    
    const stats = this.getAllStats();
    
    if (Object.keys(stats).length === 0) {
      console.log('No performance data collected');
    } else {
      for (const [operation, data] of Object.entries(stats)) {
        const threshold = this.thresholds[operation];
        const avgExceeded = threshold && parseFloat(data.avg) > threshold;
        
        console.group(
          `${operation}${avgExceeded ? ' ⚠️' : ' ✓'}${threshold ? ` (threshold: ${threshold}ms)` : ''}`
        );
        console.table(data);
        console.groupEnd();
      }
    }
    
    console.groupEnd();
  }

  /**
   * Clear all metrics
   */
  clear() {
    this.metrics.clear();
  }

  /**
   * Set custom threshold
   * @param {string} operationName - Operation name
   * @param {number} threshold - Threshold in milliseconds
   */
  setThreshold(operationName, threshold) {
    this.thresholds[operationName] = threshold;
  }

  /**
   * Measure DOM operation
   * @param {string} operationName - Operation name
   * @param {Function} domOperation - DOM operation function
   * @returns {*} Operation result
   */
  measureDOM(operationName, domOperation) {
    this.startMeasure(operationName);
    
    // Use requestAnimationFrame to measure after paint
    return new Promise((resolve) => {
      const result = domOperation();
      
      requestAnimationFrame(() => {
        this.endMeasure(operationName);
        resolve(result);
      });
    });
  }

  /**
   * Batch DOM updates to minimize reflows
   * @param {Function[]} operations - Array of DOM operation functions
   */
  batchDOMUpdates(operations) {
    this.startMeasure('batchDOMUpdates');
    
    // Use DocumentFragment for batch updates
    const fragment = document.createDocumentFragment();
    
    operations.forEach(op => op(fragment));
    
    this.endMeasure('batchDOMUpdates');
    
    return fragment;
  }
}

// Create global instance
export const performanceMonitor = new PerformanceMonitor();

// Expose to window for debugging
if (typeof window !== 'undefined') {
  window.performanceMonitor = performanceMonitor;
}
