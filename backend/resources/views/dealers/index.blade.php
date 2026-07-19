<x-app-layout>
    <div class="container mt-4">

        <div class="card shadow">

            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Dealer List</h4>

                <a href="#" class="btn btn-primary">
                    + Add Dealer
                </a>
            </div>

            <div class="card-body">

                <table class="table table-bordered table-striped table-hover">

                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Dealer Code</th>
                            <th>Firm Name</th>
                            <th>Owner Name</th>
                            <th>Mobile</th>
                            <th>Status</th>
                            <th width="160">Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        @forelse($dealers as $dealer)

                            <tr>

                                <td>{{ $loop->iteration }}</td>

                                <td>{{ $dealer->dealer_code }}</td>

                                <td>{{ $dealer->firm_name }}</td>

                                <td>{{ $dealer->owner_name }}</td>

                                <td>{{ $dealer->mobile }}</td>

                                <td>
                                    @if($dealer->status)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-danger">Inactive</span>
                                    @endif
                                </td>

                                <td>
                                    <button class="btn btn-warning btn-sm">Edit</button>

                                    <button class="btn btn-danger btn-sm">Delete</button>
                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td colspan="7" class="text-center text-muted">
                                    No Dealers Found
                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

                <div class="mt-3">
                    {{ $dealers->links() }}
                </div>

            </div>

        </div>

    </div>
</x-app-layout>