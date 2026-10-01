<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Dashboard | Sponsor Wallet System</title>

    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/dashboard.css">
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="logo">
            <h2>SW Wallet</h2>
            <p>Student Portal</p>
        </div>

        <nav class="navigation">

            <a href="#" class="nav-link active">
                <span>▣</span>
                Dashboard
            </a>

            <a href="#" class="nav-link">
                <span>▤</span>
                My Budget
            </a>

            <a href="#" class="nav-link">
                <span>↕</span>
                Spending
            </a>

            <a href="#" class="nav-link">
                <span>▣</span>
                Transactions
            </a>

            <a href="#" class="nav-link">
                <span>◉</span>
                Profile
            </a>

        </nav>

        <div class="sidebar-bottom">
            <a href="#" class="nav-link logout">
                <span>↪</span>
                Logout
            </a>
        </div>

    </aside>


    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- TOP BAR -->
        <header class="topbar">

            <div>
                <h1>Student Dashboard</h1>

                <p>
                    Manage your sponsored educational funds.
                </p>
            </div>

            <div class="student-profile">

                <div class="student-details">
                    <strong>Ali Omar</strong>
                    <small>University Student</small>
                </div>

                <div class="profile-circle">
                    A
                </div>

            </div>

        </header>


        <!-- DASHBOARD -->
        <section class="dashboard">

            <!-- WALLET -->
            <div class="wallet-card">

                <div class="wallet-information">

                    <p class="wallet-label">
                        Available Wallet Balance
                    </p>

                    <h2>
                        KSh 25,000.00
                    </h2>

                    <p class="wallet-description">
                        Funds currently available in your wallet
                    </p>

                </div>

                <div class="wallet-symbol">
                    KSh
                </div>

            </div>


            <!-- SUMMARY -->
            <div class="summary-container">

                <div class="summary-card">

                    <div class="summary-icon">
                        B
                    </div>

                    <div>
                        <p>Total Budget</p>
                        <h3>KSh 20,000</h3>
                    </div>

                </div>


                <div class="summary-card">

                    <div class="summary-icon">
                        S
                    </div>

                    <div>
                        <p>Total Spent</p>
                        <h3>KSh 5,000</h3>
                    </div>

                </div>


                <div class="summary-card">

                    <div class="summary-icon">
                        R
                    </div>

                    <div>
                        <p>Remaining Budget</p>
                        <h3>KSh 15,000</h3>
                    </div>

                </div>

            </div>


            <!-- LOWER SECTION -->
            <div class="dashboard-grid">


                <!-- BUDGET -->
                <div class="dashboard-card">

                    <div class="card-header">

                        <div>
                            <h2>My Budget</h2>
                            <p>Your current budget allocations</p>
                        </div>

                        <button id="addBudgetButton">
                            + Add Budget
                        </button>

                    </div>


                    <div class="budget-list">

                        <div class="budget-item">

                            <div>
                                <strong>Food</strong>
                                <p>Budget: KSh 5,000</p>
                            </div>

                            <span>
                                KSh 3,000 left
                            </span>

                        </div>


                        <div class="budget-item">

                            <div>
                                <strong>Transport</strong>
                                <p>Budget: KSh 3,000</p>
                            </div>

                            <span>
                                KSh 2,000 left
                            </span>

                        </div>


                        <div class="budget-item">

                            <div>
                                <strong>Academic Materials</strong>
                                <p>Budget: KSh 4,000</p>
                            </div>

                            <span>
                                KSh 4,000 left
                            </span>

                        </div>


                        <div class="budget-item">

                            <div>
                                <strong>Rent</strong>
                                <p>Budget: KSh 8,000</p>
                            </div>

                            <span>
                                KSh 6,000 left
                            </span>

                        </div>

                    </div>

                </div>


                <!-- TRANSACTIONS -->
                <div class="dashboard-card">

                    <div class="card-header">

                        <div>
                            <h2>Recent Transactions</h2>
                            <p>Your latest wallet activity</p>
                        </div>

                        <button id="viewTransactionsButton">
                            View All
                        </button>

                    </div>


                    <div class="transaction-list">


                        <div class="transaction-item">

                            <div class="transaction-information">

                                <strong>Food</strong>

                                <small>
                                    28 Sept 2026
                                </small>

                            </div>

                            <span class="expense">
                                - KSh 500
                            </span>

                        </div>


                        <div class="transaction-item">

                            <div class="transaction-information">

                                <strong>Transport</strong>

                                <small>
                                    27 Sept 2026
                                </small>

                            </div>

                            <span class="expense">
                                - KSh 300
                            </span>

                        </div>


                        <div class="transaction-item">

                            <div class="transaction-information">

                                <strong>Wallet Funding</strong>

                                <small>
                                    25 Sept 2026
                                </small>

                            </div>

                            <span class="income">
                                + KSh 20,000
                            </span>

                        </div>


                    </div>

                </div>

            </div>

        </section>

    </main>


    <script src="../js/student.js"></script>

</body>

</html>