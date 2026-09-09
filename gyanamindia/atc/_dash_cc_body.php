<?php
/** ClassChakra-style ATC dashboard body (included from index.php) */
$todayFeeTotal = $todayCash + $todayOnline;
?>
                <!-- ═══ DASHBOARD BANNERS ═══ -->
                <?php $imgPrefix = '../uploads/announcements/';
                include __DIR__ . '/../includes/banner_carousel.php'; ?>

                <?php if (!empty($tickerNotifs)): ?>
                <div class="cc-grid cc-grid-1" style="margin-bottom:1rem">
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                            </span>
                            <h3>Notice Board</h3>
                            <a class="cc-link" href="notifications.php">Show All</a>
                        </div>
                        <div class="nb-grid" style="margin:0">
                            <?php foreach ($tickerNotifs as $i => $tn):
                                $isUrgent = stripos($tn['title'], 'urgent') !== false
                                    || stripos($tn['message'], 'urgent') !== false
                                    || ($tn['priority'] ?? '') === 'High';
                                $preview = htmlspecialchars(mb_strimwidth($tn['message'], 0, 120, '…'));
                                $full = htmlspecialchars($tn['message']);
                                $date = date('d M Y', strtotime($tn['created_at']));
                                ?>
                                <div class="nb-card <?= $isUrgent ? 'nb-urgent' : '' ?>" id="nb2-<?= $i ?>">
                                    <div class="nb-card-top">
                                        <div class="nb-badge <?= $isUrgent ? 'nb-badge-urgent' : 'nb-badge-regular' ?>">
                                            <?= $isUrgent ? 'Urgent' : 'Notice' ?>
                                        </div>
                                        <span class="nb-date"><?= $date ?></span>
                                    </div>
                                    <div class="nb-title"><?= htmlspecialchars($tn['title']) ?></div>
                                    <div class="nb-preview" id="nb-prev-<?= $i ?>"><?= $preview ?></div>
                                    <div class="nb-full" id="nb-full-<?= $i ?>" style="display:none"><?= nl2br($full) ?></div>
                                    <?php if (strlen($tn['message']) > 120): ?>
                                        <button class="nb-toggle" onclick="toggleNotice(<?= $i ?>)" id="nb-btn-<?= $i ?>">Read more ▾</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Row 1: Financial -->
                <div class="cc-grid cc-grid-4">
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v18"/><path d="M5 8h7a3 3 0 0 1 0 6H8a3 3 0 0 0 0 6h11"/></svg>
                            </span>
                            <h3>Balance Amount</h3>
                        </div>
                        <div class="cc-metric-label">Pending fees (active)</div>
                        <div class="cc-metric-value red">₹ <?= number_format($pendingFees, 0) ?></div>
                    </div>
                    <div class="cc-card cc-card-pad" id="totalCollCard">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                            </span>
                            <h3>Grand Total</h3>
                            <select id="collFilter" onchange="applyCollFilter()" style="margin-left:auto;font-size:.7rem;font-weight:700;border:1px solid var(--cc-border);border-radius:8px;background:#f8fafc;color:var(--cc-text);padding:.2rem .4rem;cursor:pointer;outline:none;font-family:inherit">
                                <option value="all">All Time</option>
                                <option value="month">This Month</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="cc-metric-label">Total collected</div>
                        <div class="cc-metric-value green" id="totalCollValue">₹ <?= number_format($grandTotalCollected, 0) ?></div>
                        <div id="collCustomRange" style="display:none;margin-top:.55rem;gap:.35rem;flex-wrap:wrap">
                            <input type="date" id="collFrom" onchange="applyCollFilter()" style="font-size:.72rem;border:1px solid var(--cc-border);border-radius:8px;padding:.2rem .4rem;font-family:inherit">
                            <span style="font-size:.72rem;color:var(--cc-muted)">to</span>
                            <input type="date" id="collTo" onchange="applyCollFilter()" style="font-size:.72rem;border:1px solid var(--cc-border);border-radius:8px;padding:.2rem .4rem;font-family:inherit">
                        </div>
                        <div class="cc-kv" style="margin-top:.35rem"><span class="k" id="collSubLabel">All time via receipts</span></div>
                    </div>
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </span>
                            <h3>Today's Summary</h3>
                        </div>
                        <div class="cc-kv"><span class="k">Admissions</span><span class="v blue"><?= (int)$todayAdmissions ?></span></div>
                        <div class="cc-kv"><span class="k">Enquiries</span><span class="v"><?= (int)$todayInquiries ?></span></div>
                        <div class="cc-kv"><span class="k">Converted (all-time)</span><span class="v green"><?= (int)$convertedInquiries ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad" id="todayCollCard" onclick="openTransModal()" style="cursor:pointer" title="Click to view transactions">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            </span>
                            <h3>Today's Fee Summary</h3>
                            <input type="date" id="todayDatePicker" value="<?= date('Y-m-d') ?>"
                                style="margin-left:auto;font-size:.7rem;font-weight:700;border:1px solid var(--cc-border);border-radius:8px;background:#f8fafc;padding:.2rem .35rem;outline:none;font-family:inherit"
                                onclick="event.stopPropagation()" onchange="applyTodayFilter(event)">
                        </div>
                        <div class="cc-metric-label">Collected today</div>
                        <div class="cc-metric-value blue" id="todayCollValue">₹ <?= number_format($todayFeeTotal, 0) ?></div>
                        <div class="cc-kv"><span class="k">Cash</span><span class="v green">₹ <?= number_format($todayCash, 0) ?></span></div>
                        <div class="cc-kv"><span class="k">Online</span><span class="v blue">₹ <?= number_format($todayOnline, 0) ?></span></div>
                    </div>
                </div>

                <!-- Row 2: Entity counts -->
                <div class="cc-grid cc-grid-4">
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                            </span>
                            <h3>Courses</h3>
                        </div>
                        <div class="cc-kv"><span class="k">Total</span><span class="v"><?= (int)$totalCourses ?></span></div>
                        <div class="cc-kv"><span class="k">Active</span><span class="v green"><?= (int)$activeCourses ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            <h3>Enquiries</h3>
                        </div>
                        <div class="cc-kv"><span class="k">Total</span><span class="v"><?= (int)($totalInquiries + $totalTelephonic) ?></span></div>
                        <div class="cc-kv"><span class="k">Open</span><span class="v orange"><?= (int)$openEnquiries ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad" onclick="openExamModal('all')" style="cursor:pointer" title="View exam students">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            </span>
                            <h3>Total Exam</h3>
                        </div>
                        <div class="cc-metric-value blue"><?= (int)$totalExams ?></div>
                        <div class="cc-kv"><span class="k">Pending</span><span class="v orange"><?= (int)$pendingExams ?></span></div>
                        <div class="cc-kv"><span class="k">Conducted</span><span class="v green"><?= (int)$conductedExams ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                            </span>
                            <h3>Course Notes</h3>
                        </div>
                        <?php if (!empty($popularCourses)): ?>
                            <?php foreach (array_slice($popularCourses, 0, 3) as $pc): ?>
                                <div class="cc-kv">
                                    <span class="k" style="color:var(--cc-blue)"><?= htmlspecialchars($pc['cname']) ?></span>
                                    <span class="v"><?= (int)$pc['cnt'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cc-empty" style="padding:.75rem 0">No courses yet</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Row 3: Students + Certificates + HO -->
                <div class="cc-grid cc-grid-4">
                    <div class="cc-card cc-card-pad cc-span-2">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </span>
                            <h3>Students</h3>
                            <a class="cc-link" href="students.php">Show All</a>
                        </div>
                        <div class="cc-stat-row">
                            <div class="cc-stat-item"><span class="lbl">Total</span> <?= (int)$totalStudents ?></div>
                            <div class="cc-stat-item"><span class="cc-dot blue"></span><span class="lbl">Active</span> <?= (int)$activeStudents ?></div>
                            <div class="cc-stat-item"><span class="cc-dot green"></span><span class="lbl">Active Paid</span> <span style="color:var(--cc-green)"><?= (int)$activePaid ?></span></div>
                            <div class="cc-stat-item"><span class="cc-dot red"></span><span class="lbl">Active Unpaid</span> <span style="color:var(--cc-red)"><?= (int)$activeUnpaid ?></span></div>
                        </div>
                    </div>
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>
                            </span>
                            <h3>Certificates</h3>
                            <a class="cc-link" href="completion_certificate.php">Show All</a>
                        </div>
                        <div class="cc-kv"><span class="k">Distributed</span><span class="v green"><?= (int)$certsDistributed ?></span></div>
                        <div class="cc-kv"><span class="k">Pending</span><span class="v orange"><?= (int)$certsPending ?></span></div>
                        <div class="cc-progress"><span style="width:<?= (int)$certsPct ?>%"></span></div>
                        <div class="cc-progress-label"><?= (int)$certsPct ?>% Distributed</div>
                    </div>
                    <div class="cc-stack">
                        <div class="cc-card cc-card-pad" onclick="openHOModal('reported')" style="cursor:pointer" title="Reported to HO">
                            <div class="cc-card-head" style="margin-bottom:.4rem">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                                <h3>HO Reported</h3>
                            </div>
                            <div class="cc-metric-value green" style="font-size:1.35rem"><?= (int)$reportedCount ?></div>
                        </div>
                        <div class="cc-card cc-card-pad" onclick="openHOModal('pending')" style="cursor:pointer" title="Pending HO share">
                            <div class="cc-card-head" style="margin-bottom:.4rem">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </span>
                                <h3>HO Pending</h3>
                            </div>
                            <div class="cc-metric-value orange" style="font-size:1.35rem"><?= (int)$pendingReportCount ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($birthdays)): ?>
                <div class="cc-card" style="margin-bottom:1rem">
                    <div class="cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </span>
                            <h3>Student Birthdays Today</h3>
                            <span style="margin-left:auto;font-size:.78rem;font-weight:700;color:var(--cc-muted)"><?= count($birthdays) ?></span>
                        </div>
                        <div class="atc-bday-body" style="padding:0">
                            <?php foreach ($birthdays as $b): ?>
                                <div class="atc-bday-row">
                                    <div class="atc-bday-avatar"><?= mb_strtoupper(mb_substr(trim($b['name']), 0, 1)) ?></div>
                                    <div style="flex:1;min-width:0">
                                        <div class="atc-bday-name"><?= htmlspecialchars(trim($b['name'])) ?></div>
                                        <div class="atc-bday-meta"><?= htmlspecialchars($b['course'] ?? '') ?></div>
                                    </div>
                                    <?php if (!empty($b['mobile'])): ?>
                                        <button
                                            onclick="sendAtcBdayWish('<?= addslashes(htmlspecialchars(trim($b['name']))) ?>', '<?= htmlspecialchars($b['mobile']) ?>')"
                                            style="display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .85rem;border-radius:999px;border:none;background:#25d366;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;flex-shrink:0">
                                            Send Wish
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Row 4: Tables — enquiries & popular courses -->
                <div class="cc-grid cc-grid-4">
                    <div class="cc-card cc-span-2">
                        <div class="cc-card-pad" style="padding-bottom:.35rem">
                            <div class="cc-card-head" style="margin-bottom:.35rem">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                </span>
                                <h3>Recent Enquiries</h3>
                                <a class="cc-link" href="inquiries.php">Show All</a>
                            </div>
                        </div>
                        <?php if (empty($recentInquiries)): ?>
                            <div class="cc-empty">No recent enquiries</div>
                        <?php else: ?>
                            <table class="cc-table">
                                <thead><tr><th>Student Name</th><th>Course</th><th>Enq Date</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentInquiries as $inq): ?>
                                    <tr>
                                        <td class="name"><?= htmlspecialchars(trim($inq['name'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($inq['course_interested'] ?? '—') ?></td>
                                        <td class="muted"><?= !empty($inq['created_at']) ? date('d/m/Y', strtotime($inq['created_at'])) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="cc-card">
                        <div class="cc-card-pad" style="padding-bottom:.35rem">
                            <div class="cc-card-head" style="margin-bottom:.35rem">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                </span>
                                <h3>Popular Courses for Enquiries</h3>
                            </div>
                        </div>
                        <?php if (empty($popularEnquiryCourses)): ?>
                            <div class="cc-empty">No enquiry data</div>
                        <?php else: ?>
                            <table class="cc-table">
                                <thead><tr><th>Course Name</th><th>Students</th></tr></thead>
                                <tbody>
                                <?php foreach ($popularEnquiryCourses as $pc): ?>
                                    <tr>
                                        <td class="name"><?= htmlspecialchars($pc['cname']) ?></td>
                                        <td><strong><?= (int)$pc['cnt'] ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="cc-card">
                        <div class="cc-card-pad" style="padding-bottom:.35rem">
                            <div class="cc-card-head" style="margin-bottom:.35rem">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                </span>
                                <h3>Popular Courses for Admission</h3>
                            </div>
                        </div>
                        <?php if (empty($popularCourses)): ?>
                            <div class="cc-empty">No admissions yet</div>
                        <?php else: ?>
                            <table class="cc-table">
                                <thead><tr><th>Course Name</th><th>Students</th></tr></thead>
                                <tbody>
                                <?php foreach ($popularCourses as $pc): ?>
                                    <tr>
                                        <td class="name"><?= htmlspecialchars($pc['cname']) ?></td>
                                        <td><strong><?= (int)$pc['cnt'] ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Exam -->
                <div class="cc-card" style="margin-bottom:1rem">
                    <div class="cc-card-pad" style="padding-bottom:.35rem">
                        <div class="cc-card-head" style="margin-bottom:.35rem">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            </span>
                            <h3>Recent Exam</h3>
                            <a class="cc-link" href="javascript:void(0)" onclick="openExamModal('conducted')">Show All</a>
                        </div>
                    </div>
                    <?php if (empty($recentExamsDash)): ?>
                        <div class="cc-empty">No exam records yet</div>
                    <?php else: ?>
                        <table class="cc-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Roll No</th>
                                    <th>Exam Date</th>
                                    <th>Hall</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentExamsDash as $ex): ?>
                                <tr>
                                    <td class="name"><?= htmlspecialchars(trim($ex['student_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($ex['course'] ?? '—') ?></td>
                                    <td class="muted"><?= htmlspecialchars($ex['roll_no'] ?? '—') ?></td>
                                    <td class="muted"><?= !empty($ex['exam_date']) ? date('d M Y', strtotime($ex['exam_date'])) : '—' ?></td>
                                    <td class="muted"><?= htmlspecialchars($ex['exam_hall'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Charts -->
                <div class="cc-grid cc-grid-2">
                    <div class="cc-card">
                        <div class="cc-card-pad" style="padding-bottom:0">
                            <div class="cc-card-head">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                                </span>
                                <h3>Monthly Admissions</h3>
                            </div>
                        </div>
                        <div class="cc-chart-wrap"><canvas id="admissionsChart"></canvas></div>
                    </div>
                    <div class="cc-card">
                        <div class="cc-card-pad" style="padding-bottom:0">
                            <div class="cc-card-head">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                </span>
                                <h3>Revenue</h3>
                            </div>
                        </div>
                        <div class="cc-chart-wrap"><canvas id="revenueChart"></canvas></div>
                    </div>
                </div>

                <!-- Recent Fees -->
                <div class="cc-card" style="margin-bottom:1rem">
                    <div class="cc-card-pad" style="padding-bottom:.35rem">
                        <div class="cc-card-head" style="margin-bottom:.35rem">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            </span>
                            <h3>Recent Fees</h3>
                            <a class="cc-link" href="fees.php">Show All</a>
                        </div>
                    </div>
                    <?php if (empty($recentPayments)): ?>
                        <div class="cc-empty">No fee payments yet</div>
                    <?php else: ?>
                        <table class="cc-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt</th>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Mode</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentPayments as $pay):
                                $rcp = $pay['receipt_no'] ?? ('PAY-' . $pay['id']);
                                ?>
                                <tr>
                                    <td class="muted"><?= !empty($pay['payment_date']) ? date('d M Y', strtotime($pay['payment_date'])) : '—' ?></td>
                                    <td class="name"><?= htmlspecialchars($rcp) ?></td>
                                    <td><?= htmlspecialchars(trim($pay['student_name'] ?? '')) ?></td>
                                    <td class="name">₹ <?= number_format((float)$pay['amount'], 0) ?></td>
                                    <td><?= htmlspecialchars($pay['payment_mode'] ?? '—') ?></td>
                                    <td>
                                        <a class="cc-btn" href="fee_receipt.php?payment_id=<?= (int)$pay['id'] ?>" target="_blank" rel="noopener">Detail</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Due fees + Approvals + Quick actions -->
                <div class="cc-grid cc-grid-3">
                    <div class="cc-card">
                        <div class="cc-card-pad" style="padding-bottom:.35rem">
                            <div class="cc-card-head" style="margin-bottom:.35rem">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </span>
                                <h3>Upcoming Due Fees</h3>
                                <a class="cc-link" href="fees.php">Show All</a>
                            </div>
                        </div>
                        <?php if (empty($upcomingDueFees)): ?>
                            <div class="cc-empty">All fees cleared</div>
                        <?php else: ?>
                            <table class="cc-table">
                                <thead><tr><th>Student</th><th>Pending</th></tr></thead>
                                <tbody>
                                <?php foreach ($upcomingDueFees as $uf): ?>
                                    <tr onclick="window.location='collect_fees.php?id=<?= (int)$uf['id'] ?>'" style="cursor:pointer">
                                        <td>
                                            <div class="name"><?= htmlspecialchars(trim($uf['name'])) ?></div>
                                            <div class="muted"><?= htmlspecialchars($uf['course'] ?? '') ?></div>
                                        </td>
                                        <td class="name" style="color:var(--cc-red)">₹ <?= number_format((float)$uf['fees_pending'], 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="cc-card">
                        <div class="cc-card-pad" style="padding-bottom:.35rem">
                            <div class="cc-card-head" style="margin-bottom:.35rem">
                                <span class="cc-ico">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </span>
                                <h3>Pending Approvals</h3>
                                <span style="margin-left:auto;font-size:.78rem;font-weight:800;color:var(--cc-orange)"><?= (int)$pendingApprovalCount ?></span>
                            </div>
                        </div>
                        <?php if (empty($pendingApprovals)): ?>
                            <div class="cc-empty">All caught up</div>
                        <?php else: ?>
                            <table class="cc-table">
                                <thead><tr><th>Student</th><th>Change</th></tr></thead>
                                <tbody>
                                <?php foreach ($pendingApprovals as $pa): ?>
                                    <tr>
                                        <td class="name"><?= htmlspecialchars($pa['student_name']) ?></td>
                                        <td class="muted"><?= htmlspecialchars($pa['field_label']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <span class="cc-ico">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            </span>
                            <h3>Quick Actions</h3>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem">
                            <?php
                            $qa = [
                                ['inquiries.php', 'New Inquiry'],
                                ['new_admission.php', 'New Admission'],
                                ['fees.php', 'Collect Fees'],
                                ['students.php', 'Students'],
                                ['notifications.php', 'Notifications'],
                                ['pay_share.php', 'Pay Share'],
                            ];
                            foreach ($qa as [$href, $label]): ?>
                                <a href="<?= $href ?>" class="cc-btn ghost" style="height:38px;font-size:.78rem"><?= $label ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
