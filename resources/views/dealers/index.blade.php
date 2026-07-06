<x-app-layout>
    <div class="container mt-4">

        <div class="card shadow">

            <div class="card-header d-flex justify-content-between align-items-center">

                <h4 class="mb-0">
                    Dealer List
                </h4>

                <button class="btn btn-primary">
                    + Add Dealer
                </button>

            </div>

            <div class="card-body">

                <table class="table table-bordered table-striped">

                    <thead class="table-dark">

                        <tr>
                            <th>#</th>
                            <th>Dealer Code</th>
                            <th>Firm Name</th>
                            <th>Owner Name</th>
                            <th>Mobile</th>
                            <th>Status</th>
                            <th width="150">Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        <tr>
                            <td>1</td>
                            <td>DLR0001</td>
                            <td>ABC Traders</td>
                            <td>Ramesh Patil</td>
                            <td>9876543210</td>
                            <td>
                                <span class="badge bg-success">Active</span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning">Edit</button>
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

    </div>
</x-app-layout>