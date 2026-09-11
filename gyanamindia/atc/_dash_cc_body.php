<?php
/** ClassChakra-style ATC dashboard body (included from index.php) */
require_once __DIR__ . '/_dash_cc_icons.php';
$todayFeeTotal = $todayCash + $todayOnline;
?>
                <!-- ═══ DASHBOARD BANNERS ═══ -->
                <?php $imgPrefix = '../uploads/announcements/';
                include __DIR__ . '/../includes/banner_carousel.php'; ?>

                <?php if (!empty($tickerNotifs)): ?>
                <div class="cc-grid cc-grid-1" style="margin-bottom:1rem">
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <?= cc_ico('bell', 'xl') ?>
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
                            <?= cc_png('icon-share-revenue.png', 'xl', 'Balance') ?>
                            <h3>Balance Amount</h3>
                        </div>
                        <div class="cc-metric-label">Pending fees (active)</div>
                        <div class="cc-metric-value red">₹ <?= number_format($pendingFees, 0) ?></div>
                    </div>
                    <div class="cc-card cc-card-pad" id="totalCollCard">
                        <div class="cc-card-head">
                            <?= cc_png('icon-grand-total.png', 'xl', 'Grand total') ?>
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
                            <?= cc_png('icon-admissions.png', 'xl', "Today's summary") ?>
                            <h3>Today's Summary</h3>
                        </div>
                        <div class="cc-kv"><span class="k">Admissions</span><span class="v blue"><?= (int)$todayAdmissions ?></span></div>
                        <div class="cc-kv"><span class="k">Enquiries</span><span class="v"><?= (int)$todayInquiries ?></span></div>
                        <div class="cc-kv"><span class="k">Converted (all-time)</span><span class="v green"><?= (int)$convertedInquiries ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad" id="todayCollCard" onclick="openTransModal()" style="cursor:pointer" title="Click to view transactions">
                        <div class="cc-card-head">
                            <?= cc_png('icon-today-share.png', 'xl', "Today's fees") ?>
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
                            <?= cc_png('icon-books.png', 'xl', 'Courses') ?>
                            <h3>Courses</h3>
                        </div>
                        <div class="cc-kv"><span class="k">Total</span><span class="v"><?= (int)$totalCourses ?></span></div>
                        <div class="cc-kv"><span class="k">Active</span><span class="v green"><?= (int)$activeCourses ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <?= cc_png('icon-staff.png', 'xl', 'Enquiries') ?>
                            <h3>Enquiries</h3>
                        </div>
                        <div class="cc-kv"><span class="k">Total</span><span class="v"><?= (int)($totalInquiries + $totalTelephonic) ?></span></div>
                        <div class="cc-kv"><span class="k">Open</span><span class="v orange"><?= (int)$openEnquiries ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad" onclick="openExamModal('all')" style="cursor:pointer" title="View exam students">
                        <div class="cc-card-head">
                            <?= cc_png('icon-pending.png', 'xl', 'Total exam') ?>
                            <h3>Total Exam</h3>
                        </div>
                        <div class="cc-metric-value blue"><?= (int)$totalExams ?></div>
                        <div class="cc-kv"><span class="k">Pending</span><span class="v orange"><?= (int)$pendingExams ?></span></div>
                        <div class="cc-kv"><span class="k">Conducted</span><span class="v green"><?= (int)$conductedExams ?></span></div>
                    </div>
                    <div class="cc-card cc-card-pad">
                        <div class="cc-card-head">
                            <?= cc_png('icon-books.png', 'xl', 'Course notes') ?>
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
                            <?= cc_png('icon-admissions.png', 'xl', 'Students') ?>
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
                            <?= cc_png('icon-certified.png', 'xl', 'Certificates') ?>
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
                                <?= cc_png('icon-reported.png', 'sm', 'HO Reported') ?>
                            <h3>HO Reported</h3>
                            </div>
                            <div class="cc-metric-value green" style="font-size:1.35rem"><?= (int)$reportedCount ?></div>
                        </div>
                        <div class="cc-card cc-card-pad" onclick="openHOModal('pending')" style="cursor:pointer" title="Pending HO share">
                            <div class="cc-card-head" style="margin-bottom:.4rem">
                                <?= cc_png('icon-pending.png', 'sm', 'HO Pending') ?>
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
                            <?= cc_ico('cake', 'xl') ?>
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
                                <?= cc_png('icon-staff.png', 'xl', 'Recent enquiries') ?>
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
                                <?= cc_png('icon-books.png', 'xl', 'Popular enquiry courses') ?>
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
                                <?= cc_png('icon-books.png', 'xl', 'Popular admission courses') ?>
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
                            <?= cc_png('icon-pending.png', 'xl', 'Recent exam') ?>
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

                <!-- Analytics Overview (pie / bar / line — same pattern as Admin) -->
                <?php
                $pieLabels = array_column($chartByCourse, 'label');
                $pieData = array_map('intval', array_column($chartByCourse, 'students'));
                if (array_sum($pieData) <= 0) {
                    $pieLabels = array_keys(array_filter($chartFeeStatus));
                    $pieData = array_values(array_filter($chartFeeStatus));
                    $pieTitle = 'Fee Status';
                    $pieSub = 'Active students by fee payment status';
                } else {
                    $pieTitle = 'Students by Course';
                    $pieSub = 'Admissions mix at your centre';
                }
                $barLabels = array_map(static function ($r) {
                    $n = (string)($r['label'] ?? 'Course');
                    return mb_strlen($n) > 18 ? (mb_substr($n, 0, 16) . '…') : $n;
                }, $chartByCourse);
                $barData = array_map(static function ($r) {
                    return round((float)($r['collected'] ?? 0), 0);
                }, $chartByCourse);
                ?>
                <div class="cc-analytics-head">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                    Analytics Overview
                </div>
                <div class="cc-analytics-grid">
                    <div class="cc-analytics-card">
                        <h3><?= htmlspecialchars($pieTitle) ?></h3>
                        <div class="cc-analytics-sub"><?= htmlspecialchars($pieSub) ?></div>
                        <?php if (!empty($pieData) && array_sum($pieData) > 0): ?>
                        <div class="cc-analytics-canvas"><canvas id="atcPieChart"></canvas></div>
                        <?php else: ?>
                        <div class="cc-analytics-empty">No course data yet</div>
                        <?php endif; ?>
                    </div>
                    <div class="cc-analytics-card span-2">
                        <h3>Fees by Course</h3>
                        <div class="cc-analytics-sub">Collected fees (active students) by course</div>
                        <?php if (!empty($barData) && array_sum($barData) > 0): ?>
                        <div class="cc-analytics-canvas"><canvas id="atcBarChart"></canvas></div>
                        <?php else: ?>
                        <div class="cc-analytics-empty">No fee collection data yet</div>
                        <?php endif; ?>
                    </div>
                    <div class="cc-analytics-card span-full">
                        <h3>Monthly Trend</h3>
                        <div class="cc-analytics-sub">Admissions &amp; fee revenue — last 6 months</div>
                        <?php if (!empty($monthlyLabels)): ?>
                        <div class="cc-analytics-canvas tall"><canvas id="atcLineChart"></canvas></div>
                        <?php else: ?>
                        <div class="cc-analytics-empty">No monthly trend data yet</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Fees -->
                <div class="cc-card" style="margin-bottom:1rem">
                    <div class="cc-card-pad" style="padding-bottom:.35rem">
                        <div class="cc-card-head" style="margin-bottom:.35rem">
                            <?= cc_png('icon-today-share.png', 'xl', 'Recent fees') ?>
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
                                <?= cc_png('icon-pending.png', 'xl', 'Due fees') ?>
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
                                <?= cc_png('icon-material.png', 'xl', 'Pending approvals') ?>
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
                            <?= cc_png('icon-dispatch.png', 'xl', 'Quick actions') ?>
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
