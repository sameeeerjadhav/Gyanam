# Services

This directory contains service modules for API communication and data storage.

## APIClient

The `APIClient` class handles all HTTP communication with the backend API. Currently operates in mock mode for development.

### Features

- **Mock Data Support**: Returns realistic mock data for all endpoints
- **Network Delay Simulation**: Simulates 100-300ms network latency
- **Error Handling**: Graceful error handling with user-friendly messages
- **Retry Logic Structure**: Prepared for future exponential backoff retry implementation
- **Standardized Error Responses**: All errors include error code, operation name, and original error for debugging
- **Future-Ready**: Structured for seamless Laravel backend integration

### Methods

#### `authenticate(credentials)`
Authenticates user with hall ticket credentials.

**Parameters:**
- `credentials.identifier` - Student ID or username
- `credentials.centerName` - Exam center name
- `credentials.examSlot` - Exam slot identifier
- `credentials.timeWindow` - Time window identifier

**Returns:** Authentication response with JWT token and user data

#### `getExamConfig(examId)`
Fetches exam configuration including duration, security settings, and instructions.

**Parameters:**
- `examId` - Exam identifier (e.g., 'exam_demo_001', 'exam_main_001')

**Returns:** Exam configuration object

#### `getQuestionBank(examId)`
Fetches all questions for an exam (50 for demo, 100 for main exams).

**Parameters:**
- `examId` - Exam identifier

**Returns:** Array of question objects

#### `submitExam(submission)`
Submits completed exam with all answers and security events.

**Parameters:**
- `submission` - Complete submission payload with answers, timestamps, and metadata

**Returns:** Submission result with score and pass/fail status

#### `getExamHistory(studentId)`
Fetches exam history for a student.

**Parameters:**
- `studentId` - Student identifier

**Returns:** Array of exam history records

#### `getCertificates(studentId)`
Fetches certificates earned by a student.

**Parameters:**
- `studentId` - Student identifier

**Returns:** Array of certificate objects

#### `logSecurityEvent(event)`
Logs security events (tab switches, full-screen exits, etc.).

**Parameters:**
- `event` - Security event object with type, timestamp, and metadata

**Returns:** Success confirmation

### Usage Example

```javascript
import APIClient from './services/APIClient.js';

const apiClient = new APIClient();

// Authenticate with error handling
try {
  const authResponse = await apiClient.authenticate({
    identifier: 'STUDENT123',
    centerName: 'Center A',
    examSlot: 'SLOT1',
    timeWindow: 'MORNING'
  });
  console.log('Authenticated:', authResponse.user.name);
} catch (error) {
  // Error includes user-friendly message, error code, and operation name
  console.error('Authentication failed:', error.message);
  console.error('Error code:', error.code); // e.g., 'VALIDATION_ERROR', 'NETWORK_ERROR'
  console.error('Operation:', error.operation); // e.g., 'authenticate'
}

// Get exam configuration
try {
  const examConfig = await apiClient.getExamConfig('exam_demo_001');
  console.log('Exam:', examConfig.title);
} catch (error) {
  console.error('Failed to load exam:', error.message);
}

// Get questions
const questions = await apiClient.getQuestionBank('exam_demo_001');

// Submit exam
const result = await apiClient.submitExam({
  submissionId: 'sub_001',
  examId: 'exam_demo_001',
  studentId: 'student_123',
  startTime: '2024-01-15T09:00:00Z',
  submitTime: '2024-01-15T09:30:00Z',
  duration: 30,
  answers: [...],
  security: {...},
  metadata: {...}
});
```

### Error Handling

All API methods return standardized errors with the following structure:

```javascript
{
  message: "User-friendly error message",
  code: "ERROR_CODE", // NETWORK_ERROR, TIMEOUT_ERROR, AUTH_ERROR, NOT_FOUND_ERROR, SERVER_ERROR, VALIDATION_ERROR, UNKNOWN_ERROR
  operation: "methodName", // The API method that failed
  originalError: Error // The original error object for debugging
}
```

Error codes:
- **NETWORK_ERROR**: Unable to connect to server (network issues)
- **TIMEOUT_ERROR**: Request took too long to complete
- **AUTH_ERROR**: Authentication failed
- **NOT_FOUND_ERROR**: Requested resource not found
- **SERVER_ERROR**: Server-side error occurred
- **VALIDATION_ERROR**: Invalid input data
- **UNKNOWN_ERROR**: Unexpected error occurred

### Retry Logic

The APIClient includes a retry configuration structure for future implementation:

```javascript
retryConfig: {
  maxRetries: 3,
  initialDelay: 1000, // 1 second
  maxDelay: 10000, // 10 seconds
  backoffMultiplier: 2 // Exponential backoff
}
```

Currently, retry logic is not active (single attempt only). The structure is prepared for future activation when connecting to the real backend API. The commented implementation in `_executeWithRetry` shows how retry logic with exponential backoff will work.

### Demo

Open `APIClient.demo.html` in a browser to test all API methods interactively.

### Mock Data

The APIClient currently returns mock data:

- **Demo Exam**: 30 minutes, 20 questions, relaxed security
- **Main Exam**: 60 minutes, 40 questions, strict security (full-screen, camera)
- **Question Bank**: Auto-generated arithmetic questions
- **Exam History**: Sample completed and upcoming exams
- **Certificates**: Sample issued certificate

### Future Backend Integration

To switch to real backend API:

1. Set `mockMode = false` in APIClient constructor
2. Configure `baseURL` to point to Laravel API
3. Uncomment the fetch() calls in each method
4. Add JWT token handling in request headers

The mock implementation matches the expected backend API structure, so no frontend code changes will be needed.

## StorageService

The `StorageService` class provides localStorage abstraction with error handling.

See `StorageService.js` for implementation details.
