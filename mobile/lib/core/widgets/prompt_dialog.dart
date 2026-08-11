import 'package:flutter/material.dart';

Future<String?> promptRemarkDialog(
  BuildContext context, {
  required String title,
  String label = 'Reason / Remarks',
  String submitLabel = 'Submit',
  bool required = true,
  int minLength = 3,
}) async {
  final controller = TextEditingController();
  final formKey = GlobalKey<FormState>();

  final result = await showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Form(
        key: formKey,
        child: TextFormField(
          controller: controller,
          maxLines: 3,
          decoration: InputDecoration(
            labelText: required ? '$label *' : label,
          ),
          validator: (value) {
            final text = value?.trim() ?? '';
            if (required && text.isEmpty) {
              return 'Remarks are required.';
            }
            if (required && text.length < minLength) {
              return 'Remarks must be at least $minLength characters.';
            }
            return null;
          },
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: () {
            if (!(formKey.currentState?.validate() ?? false)) {
              return;
            }
            Navigator.pop(context, controller.text.trim());
          },
          child: Text(submitLabel),
        ),
      ],
    ),
  );

  if (required && (result == null || result.isEmpty)) return null;
  return result;
}

Future<bool> confirmAction(
  BuildContext context, {
  required String title,
  required String message,
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: () => Navigator.pop(context, true),
          child: const Text('Confirm'),
        ),
      ],
    ),
  );
  return result == true;
}
