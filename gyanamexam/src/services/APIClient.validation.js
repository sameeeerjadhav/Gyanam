/**
 * Validation script for APIClient implementation
 * Run this in a browser console to verify all methods work correctly
 */

import APIClient from './APIClient.js';

async function validateAPIClient() {
  console.log('🧪 Starting APIClient Validation...\n');
  
  const apiClient = new APIClient();
  const results = {
    passed: 0,
    failed: 0,
    tests: []
  };

  function logTest(name, passed, message) {
    const status = passed ? '✅ PASS' : '❌ FAIL';
    console.log(`${status}: ${name}`);
    if (message) console.log(`   ${message}`);
    results.tests.push({ name, passed, message });
    if (passed) results.passed++;
    else results.failed++;
  }

  // Test 1: Authenticate with valid credentials
  try {
    const authResponse = await apiClient.authenticate({
      identifier: 'STUDENT123',
      centerName: 'Center A',
      examSlot: 'SLOT1',
      timeWindow: 'MORNING'
    });
    
    logTest(
      'authenticate() with valid credentials',
      authResponse.success && authResponse.token && authResponse.user,
      `Token: ${authResponse.token.substring(0, 20)}...`
    );
  } catch (error) {
    logTest('authenticate() with valid credentials', false, error.message);
  }

  // Test 2: Authenticate with missing credentials
  try {
    await apiClient.authenticate({
      identifier: 'STUDENT123',
      centerName: 'Center A'
    });
    logTest('authenticate() with missing credentials', false, 'Should have thrown error');
  } catch (error) {
    logTest(
      'authenticate() with missing credentials',
      error.message.includes('required'),
      'Correctly throws error for missing fields'
    );
  }

  // Test 3: Get demo exam config
  try {
    const config = await apiClient.getExamConfig('exam_demo_001');
    logTest(
      'getExamConfig() for demo exam',
      config.examId === 'exam_demo_001' && config.examType === 'demo' && config.duration === 30,
      `Duration: ${config.duration}min, Questions: ${config.totalQuestions}`
    );
  } catch (error) {
    logTest('getExamConfig() for demo exam', false, error.message);
  }

  // Test 4: Get main exam config
  try {
    const config = await apiClient.getExamConfig('exam_main_001');
    logTest(
      'getExamConfig() for main exam',
      config.examId === 'exam_main_001' && config.examType === 'main' && config.duration === 60,
      `Duration: ${config.duration}min, Questions: ${config.totalQuestions}, Full-screen: ${config.security.fullScreenRequired}`
    );
  } catch (error) {
    logTest('getExamConfig() for main exam', false, error.message);
  }

  // Test 5: Get invalid exam config
  try {
    await apiClient.getExamConfig('invalid_exam');
    logTest('getExamConfig() with invalid ID', false, 'Should have thrown error');
  } catch (error) {
    logTest(
      'getExamConfig() with invalid ID',
      error.message.includes('not found'),
      'Correctly throws error for invalid exam ID'
    );
  }

  // Test 6: Get question bank for demo exam
  try {
    const questions = await apiClient.getQuestionBank('exam_demo_001');
    logTest(
      'getQuestionBank() for demo exam',
      Array.isArray(questions) && questions.length === 50,
      `Returned ${questions.length} questions`
    );
  } catch (error) {
    logTest('getQuestionBank() for demo exam', false, error.message);
  }

  // Test 7: Get question bank for main exam
  try {
    const questions = await apiClient.getQuestionBank('exam_main_001');
    logTest(
      'getQuestionBank() for main exam',
      Array.isArray(questions) && questions.length === 100,
      `Returned ${questions.length} questions`
    );
  } catch (error) {
    logTest('getQuestionBank() for main exam', false, error.message);
  }

  // Test 8: Verify question structure
  try {
    const questions = await apiClient.getQuestionBank('exam_demo_001');
    const q = questions[0];
    const hasRequiredFields = q.id && q.type && q.text && q.options && 
                              Array.isArray(q.options) && q.options.length === 4;
    logTest(
      'Question structure validation',
      hasRequiredFields,
      `Type: ${q.type}, Options: ${q.options.length}`
    );
  } catch (error) {
    logTest('Question structure validation', false, error.message);
  }

  // Test 9: Submit exam
  try {
    const submission = {
      submissionId: 'sub_test_' + Date.now(),
      examId: 'exam_demo_001',
      studentId: 'student_123',
      startTime: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
      submitTime: new Date().toISOString(),
      duration: 30,
      answers: [
        { questionId: 'q1', answer: 'opt1', markedForReview: false, timeSpent: 45 },
        { questionId: 'q2', answer: 'opt2', markedForReview: false, timeSpent: 60 }
      ],
      security: {
        tabSwitchCount: 0,
        fullScreenExitCount: 0,
        events: []
      },
      metadata: {
        browser: 'Chrome 120',
        os: 'Windows 10'
      }
    };

    const result = await apiClient.submitExam(submission);
    logTest(
      'submitExam()',
      result.success && result.submissionId && typeof result.score === 'number',
      `Score: ${result.score}%, Result: ${result.result}`
    );
  } catch (error) {
    logTest('submitExam()', false, error.message);
  }

  // Test 10: Get exam history
  try {
    const history = await apiClient.getExamHistory('student_123');
    logTest(
      'getExamHistory()',
      Array.isArray(history) && history.length > 0,
      `Returned ${history.length} exam records`
    );
  } catch (error) {
    logTest('getExamHistory()', false, error.message);
  }

  // Test 11: Get certificates
  try {
    const certificates = await apiClient.getCertificates('student_123');
    logTest(
      'getCertificates()',
      Array.isArray(certificates) && certificates.length > 0,
      `Returned ${certificates.length} certificates`
    );
  } catch (error) {
    logTest('getCertificates()', false, error.message);
  }

  // Test 12: Log security event
  try {
    const event = {
      type: 'tab_switch',
      timestamp: Date.now(),
      examId: 'exam_main_001',
      studentId: 'student_123',
      questionIndex: 5,
      metadata: { duration: 3000 }
    };

    const result = await apiClient.logSecurityEvent(event);
    logTest(
      'logSecurityEvent()',
      result.success && result.logged,
      'Event logged successfully'
    );
  } catch (error) {
    logTest('logSecurityEvent()', false, error.message);
  }

  // Test 13: Network delay simulation
  try {
    const startTime = Date.now();
    await apiClient.getExamConfig('exam_demo_001');
    const duration = Date.now() - startTime;
    
    logTest(
      'Network delay simulation',
      duration >= 100 && duration < 400,
      `Delay: ${duration}ms (expected 100-300ms)`
    );
  } catch (error) {
    logTest('Network delay simulation', false, error.message);
  }

  // Summary
  console.log('\n' + '='.repeat(50));
  console.log('📊 Validation Summary');
  console.log('='.repeat(50));
  console.log(`✅ Passed: ${results.passed}`);
  console.log(`❌ Failed: ${results.failed}`);
  console.log(`📈 Success Rate: ${Math.round((results.passed / (results.passed + results.failed)) * 100)}%`);
  
  if (results.failed === 0) {
    console.log('\n🎉 All tests passed! APIClient is working correctly.');
  } else {
    console.log('\n⚠️  Some tests failed. Please review the implementation.');
  }

  return results;
}

// Auto-run validation
validateAPIClient().catch(console.error);

export default validateAPIClient;
