/**
 * Mock Question Bank
 * Sample questions for testing the exam interface
 */

export const mockQuestions = [
  {
    id: 'q1',
    type: 'multiple-choice-single',
    text: 'What is the capital of France?',
    options: [
      { id: 'a', text: 'London' },
      { id: 'b', text: 'Paris' },
      { id: 'c', text: 'Berlin' },
      { id: 'd', text: 'Madrid' }
    ],
    correctAnswer: 'b'
  },
  {
    id: 'q2',
    type: 'multiple-choice-single',
    text: 'Which programming language is known for web development?',
    options: [
      { id: 'a', text: 'Python' },
      { id: 'b', text: 'JavaScript' },
      { id: 'c', text: 'C++' },
      { id: 'd', text: 'Java' }
    ],
    correctAnswer: 'b'
  },
  {
    id: 'q3',
    type: 'multiple-choice-single',
    text: 'What does HTML stand for?',
    options: [
      { id: 'a', text: 'Hyper Text Markup Language' },
      { id: 'b', text: 'High Tech Modern Language' },
      { id: 'c', text: 'Home Tool Markup Language' },
      { id: 'd', text: 'Hyperlinks and Text Markup Language' }
    ],
    correctAnswer: 'a'
  },
  {
    id: 'q4',
    type: 'multiple-choice-single',
    text: 'Which of the following is a JavaScript framework?',
    options: [
      { id: 'a', text: 'Django' },
      { id: 'b', text: 'Flask' },
      { id: 'c', text: 'React' },
      { id: 'd', text: 'Laravel' }
    ],
    correctAnswer: 'c'
  },
  {
    id: 'q5',
    type: 'multiple-choice-single',
    text: 'What is the result of 2 + 2 * 2?',
    options: [
      { id: 'a', text: '6' },
      { id: 'b', text: '8' },
      { id: 'c', text: '4' },
      { id: 'd', text: '10' }
    ],
    correctAnswer: 'a'
  },
  {
    id: 'q6',
    type: 'multiple-choice-single',
    text: 'Which CSS property is used to change text color?',
    options: [
      { id: 'a', text: 'font-color' },
      { id: 'b', text: 'text-color' },
      { id: 'c', text: 'color' },
      { id: 'd', text: 'text-style' }
    ],
    correctAnswer: 'c'
  },
  {
    id: 'q7',
    type: 'multiple-choice-single',
    text: 'What does SQL stand for?',
    options: [
      { id: 'a', text: 'Structured Query Language' },
      { id: 'b', text: 'Simple Question Language' },
      { id: 'c', text: 'Standard Query Language' },
      { id: 'd', text: 'Structured Question Language' }
    ],
    correctAnswer: 'a'
  },
  {
    id: 'q8',
    type: 'multiple-choice-single',
    text: 'Which HTTP method is used to retrieve data?',
    options: [
      { id: 'a', text: 'POST' },
      { id: 'b', text: 'GET' },
      { id: 'c', text: 'PUT' },
      { id: 'd', text: 'DELETE' }
    ],
    correctAnswer: 'b'
  },
  {
    id: 'q9',
    type: 'multiple-choice-single',
    text: 'What is the purpose of Git?',
    options: [
      { id: 'a', text: 'Database management' },
      { id: 'b', text: 'Version control' },
      { id: 'c', text: 'Web hosting' },
      { id: 'd', text: 'Code compilation' }
    ],
    correctAnswer: 'b'
  },
  {
    id: 'q10',
    type: 'multiple-choice-single',
    text: 'Which symbol is used for comments in JavaScript?',
    options: [
      { id: 'a', text: '#' },
      { id: 'b', text: '//' },
      { id: 'c', text: '/* */' },
      { id: 'd', text: 'Both b and c' }
    ],
    correctAnswer: 'd'
  }
];

export const mockExamConfig = {
  examId: 'demo-exam-001',
  examType: 'demo',
  title: 'Demo Examination',
  duration: 10, // 10 minutes
  questionCount: 10,
  security: {
    fullScreenRequired: false,
    cameraRequired: false,
    microphoneRequired: false,
    tabSwitchLimit: 5
  }
};
