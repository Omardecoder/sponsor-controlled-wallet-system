document.addEventListener("DOMContentLoaded", function () {

    console.log("Student Dashboard Loaded");

    const addBudgetButton =
        document.getElementById("addBudgetButton");

    const viewTransactionsButton =
        document.getElementById("viewTransactionsButton");


    addBudgetButton.addEventListener("click", function () {

        alert("Budget management will be available here.");

    });


    viewTransactionsButton.addEventListener("click", function () {

        alert("Full transaction history will be displayed here.");

    });

});